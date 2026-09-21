// assets/js/doctor-my-schedule-overrides.js
// GabayMed — Doctor Portal: My Schedule page.
// Two independent pieces on this page, both REAL and backend-connected
// against doctor/my-schedule-actions.php:
//   1. "Update Availability" modal - create/reset the doctor's own
//      doctor_schedule_overrides row for a date (save_override /
//      delete_override / get_override), with a LIVE conflict preview
//      (preview_override_impact) that re-checks on every date/status/
//      hours change and lists exactly which patients would be affected -
//      replacing an older flat "any booking that day" count that
//      couldn't tell that e.g. half_day only orphans appointments
//      outside the kept hours. The confirmation before saving is a
//      second deliberate click on Save itself (button relabels to
//      "Confirm & Save Anyway"), not a browser confirm() dialog, which
//      is too easy to dismiss without reading.
//   2. "Needs Follow-Up" panel - reschedule or cancel one of the
//      doctor's own appointments that an override orphaned
//      (get_slots / reschedule / cancel_appointment). The reschedule
//      slot-grid rendering is in the sibling file
//      doctor-schedule-reschedule.js (kept separate since it's a fair
//      amount of markup-generation code on its own, mirroring
//      assets/js/staff-schedule-reschedule.js).
//
// Adapted from assets/js/doctor-schedule-overrides.js (admin's Weekly
// Overrides tab) - same modal/field IDs pattern, prefixed "ms" instead of
// "wo" so both files can coexist if ever loaded on the same page, and
// with the doctor-select field removed since there's only ever one
// doctor here: whoever is logged in.

(function () {
    'use strict';

    const backdrop = document.getElementById('msOverrideModalBackdrop');
    if (!backdrop) return;

    const csrfToken = window.msCsrfToken || '';
    const todayDate = window.msTodayDate || '';

    /* ============================= Toasts ============================= */

    function showToast(message) {
        let host = document.getElementById('msToastHost');
        if (!host) {
            host = document.createElement('div');
            host.className = 'ds-toast-host';
            host.id = 'msToastHost';
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

    /* ============================ Update Availability modal ============================= */

    const modalTitle = document.getElementById('msOverrideModalTitle');
    const modalError = document.getElementById('msModalError');
    const modalApptWarning = document.getElementById('msModalAppointmentsWarning');
    const fieldDate = document.getElementById('msFieldDate');
    const fieldDateTo = document.getElementById('msFieldDateTo');
    const fieldStart = document.getElementById('msFieldStart');
    const fieldEnd = document.getElementById('msFieldEnd');
    const fieldStatus = document.getElementById('msFieldStatus');
    const fieldReason = document.getElementById('msFieldReason');
    const fieldNotes = document.getElementById('msFieldNotes');
    const closeBtn = document.getElementById('msOverrideModalClose');
    const cancelBtn = document.getElementById('msOverrideModalCancel');
    const saveBtn = document.getElementById('msOverrideModalSave');

    // Statuses that require start/end times to be filled in
    const statusesWithHours = ['available', 'half_day', 'training', 'meeting'];

    // Whichever appointments preview_override_impact most recently found
    // for the CURRENT date/status/hours combination in the form - this is
    // what the warning box and the save-confirmation gate both read from,
    // instead of the old flat "any booking that day" count get_override
    // used to provide (which couldn't tell that e.g. a half_day override
    // only orphans appointments outside the kept hours, not every booking
    // that day).
    let previewCount = 0;
    let previewAppointments = [];
    let previewToken = 0; // guards against a slow, stale response landing after a newer one

    // True once the doctor has seen the current warning and clicked Save
    // once already - the next click actually saves. Reset to false any
    // time the fields change, so a stale acknowledgment from a different
    // date/status combination can never carry over silently.
    let impactAcknowledged = false;

    function showModalError(message) {
        if (!modalError) return;
        modalError.textContent = message;
        modalError.classList.toggle('rs-modal-error-visible', !!message);
    }

    function renderImpactWarning() {
        if (!modalApptWarning) return;

        if (previewCount === 0) {
            modalApptWarning.style.display = 'none';
            saveBtn.textContent = 'Save';
            return;
        }

        const list = previewAppointments.slice(0, 5)
            .map(a => `${a.patient_name} (${a.time})`)
            .join(', ');
        const extra = previewAppointments.length > 5 ? `, and ${previewAppointments.length - 5} more` : '';

        modalApptWarning.innerHTML =
            `<strong>This will affect ${previewCount} appointment${previewCount === 1 ? '' : 's'}:</strong> ${list}${extra}. ` +
            `They won't be cancelled automatically - you can reschedule or cancel them from the Needs Follow-Up panel right after saving.`;
        modalApptWarning.style.display = '';

        saveBtn.textContent = impactAcknowledged ? 'Confirm & Save Anyway' : 'Save';
    }

    // Re-checks impact against the CURRENT form values (not whatever was
    // loaded when the modal opened) - runs on every date/status/hours
    // change so the warning is always accurate to what's about to be
    // submitted, not stale from a previous selection.
    async function refreshImpactPreview() {
        const dateStr = fieldDate.value;
        const status = fieldStatus.value;
        if (!dateStr || !status) return;

        const myToken = ++previewToken;
        try {
            const data = await postAction('preview_override_impact', {
                override_date: dateStr,
                override_date_to: fieldDateTo.value,
                status: status,
                start_time: fieldStart.value,
                end_time: fieldEnd.value,
            });
            if (myToken !== previewToken) return; // a newer check has since started; drop this one

            previewCount = data.count || 0;
            previewAppointments = data.appointments || [];
            impactAcknowledged = false; // fields changed since - re-require a fresh look
            renderImpactWarning();
        } catch (err) {
            // Non-fatal - the doctor can still save; they just won't see
            // a preview this particular time (e.g. a network hiccup).
        }
    }

    // Fetches this date's actual override state from the server (rather
    // than relying on any locally-cached blob) - this is what makes the
    // header-level "Update Availability" button work correctly for a date
    // outside whichever week happens to be on screen, not just the 7 days
    // currently rendered in day cards. "Repeat Through" always starts
    // blank here regardless of what's loaded - each date is still its own
    // row under the hood, so a range is only ever something the doctor
    // deliberately opts into for THIS save, not a property of the
    // existing single-date record being edited.
    async function loadOverrideFor(dateStr) {
        fieldDateTo.value = '';
        fieldStart.value = '';
        fieldEnd.value = '';
        fieldStatus.value = 'emergency_leave';
        fieldReason.value = '';
        fieldNotes.value = '';
        previewCount = 0;
        previewAppointments = [];
        impactAcknowledged = false;
        renderImpactWarning();

        try {
            const data = await postAction('get_override', { override_date: dateStr });
            modalTitle.textContent = data.status !== 'no_change' ? 'Edit Availability' : 'Update Availability';
            if (data.status !== 'no_change') {
                fieldStatus.value = data.status;
                fieldStart.value = data.start || '';
                fieldEnd.value = data.end || '';
                fieldReason.value = data.reason || '';
                fieldNotes.value = data.notes || '';
            }
        } catch (err) {
            showModalError(err.message);
        }

        refreshImpactPreview();
    }

    function openModal(dateStr) {
        const effectiveDate = dateStr || todayDate;

        showModalError('');
        fieldDate.value = effectiveDate;
        if (todayDate) fieldDate.min = todayDate;
        fieldDateTo.min = effectiveDate;

        document.body.classList.add('modal-open');
        backdrop.classList.add('active');

        loadOverrideFor(effectiveDate);
    }

    function closeModal() {
        backdrop.classList.remove('active');
        document.body.classList.remove('modal-open');
    }

    if (closeBtn) closeBtn.addEventListener('click', closeModal);
    if (cancelBtn) cancelBtn.addEventListener('click', closeModal);
    backdrop.addEventListener('click', (e) => {
        if (e.target === backdrop) closeModal();
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && backdrop.classList.contains('active')) closeModal();
    });

    if (fieldStatus) {
        fieldStatus.addEventListener('change', refreshImpactPreview);
    }
    if (fieldDate) {
        fieldDate.addEventListener('change', () => {
            if (fieldDate.value) {
                fieldDateTo.min = fieldDate.value;
                loadOverrideFor(fieldDate.value);
            }
        });
    }
    if (fieldStart) fieldStart.addEventListener('change', refreshImpactPreview);
    if (fieldEnd) fieldEnd.addEventListener('change', refreshImpactPreview);
    if (fieldDateTo) fieldDateTo.addEventListener('change', refreshImpactPreview);

    document.querySelectorAll('.my-schedule-avail-btn').forEach(btn => {
        btn.addEventListener('click', () => openModal(btn.dataset.date));
    });

    const headerOpenBtn = document.getElementById('msOpenModalBtn');
    if (headerOpenBtn) {
        headerOpenBtn.addEventListener('click', () => openModal(null));
    }

    document.querySelectorAll('.my-schedule-reset-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            if (btn.disabled) return;
            const dateStr = btn.dataset.date;
            if (!confirm('Remove this override? The date will fall back to your recurring schedule. Any patients already booked that day are unaffected by this action.')) return;
            btn.disabled = true;
            try {
                await postAction('delete_override', { override_date: dateStr });
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
            const dateToStr = fieldDateTo.value;
            const isRange = !!dateToStr && dateToStr !== dateStr;

            if (!dateStr) {
                showModalError('Please choose a date.');
                return;
            }
            if (todayDate && dateStr < todayDate) {
                showModalError("You can't set availability for a past date.");
                return;
            }
            if (dateToStr && dateToStr < dateStr) {
                showModalError("\"Repeat Through\" can't be before the date above.");
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

            // First click on a change that affects patients just shows
            // the warning and asks for a second, deliberate click -
            // rather than a browser confirm() dialog, which is easy to
            // dismiss without reading. Any field change resets this (see
            // refreshImpactPreview), so it can't carry over stale.
            if (previewCount > 0 && !impactAcknowledged) {
                impactAcknowledged = true;
                renderImpactWarning();
                return;
            }

            saveBtn.disabled = true;
            try {
                if (isRange) {
                    await postAction('save_override_range', {
                        override_date_from: dateStr,
                        override_date_to: dateToStr,
                        status: fieldStatus.value,
                        start_time: fieldStart.value,
                        end_time: fieldEnd.value,
                        reason: fieldReason.value.trim(),
                        notes: fieldNotes.value.trim(),
                    });
                } else {
                    await postAction('save_override', {
                        override_date: dateStr,
                        status: fieldStatus.value,
                        start_time: fieldStart.value,
                        end_time: fieldEnd.value,
                        reason: fieldReason.value.trim(),
                        notes: fieldNotes.value.trim(),
                    });
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

    /* ==================== Needs Follow-Up panel: Cancel button ==================== */

    document.querySelectorAll('.ms-cancel-appt-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            if (btn.disabled) return;
            if (!confirm('Cancel this appointment? The patient will be notified to book a new one. Only do this if a Reschedule slot genuinely won\'t work.')) return;
            btn.disabled = true;
            try {
                await postAction('cancel_appointment', { appointment_id: btn.dataset.appointmentId });
                window.location.reload();
            } catch (err) {
                showToast(err.message);
                btn.disabled = false;
            }
        });
    });

    // Warn before leaving with the modal open and edits pending in it.
    window.addEventListener('beforeunload', (e) => {
        if (backdrop.classList.contains('active')) {
            e.preventDefault();
            e.returnValue = '';
        }
    });
})();