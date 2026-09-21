// assets/js/doctor-schedule-period-builder.js
// GabayMed — Admin Portal: Doctor Schedule page, "Set Up OPD Schedule for
// a New Period" builder on the Weekly Recurring Schedule tab.
//
// Problem this solves: doctor_weekly_schedules is versioned per weekday +
// effective date range, which works well for a doctor whose pattern never
// changes (one open-ended row per weekday, set once). The friction shows
// up at a hospital cycle rollover when a doctor's OPD hours actually
// change - doing that one weekday-row at a time (close the old row, add
// a new one, repeat x7, repeat per doctor) is what this builder replaces
// with a single "load current pattern -> edit -> publish" pass.
//
// Talks to two actions on doctor-schedule-actions.php:
//   - get_opd_pattern: prefills the 7-day grid from whatever's active as
//     of the period's start date
//   - publish_opd_schedule: one transaction that trims/supersedes every
//     overlapping row per weekday and inserts the new one, for all 7
//     days at once
//
// Neither action ever touches doctor_schedule_overrides (doctor-owned)
// or appointment_reschedule_history (the Reschedule History tab's audit
// trail) - this only ever reads/writes doctor_weekly_schedules, the same
// table create_schedule/update_schedule (doctor-schedule-weekly.js)
// already write to.
//
// FIXED (2026-07-28): the grid used to stay editable while get_opd_pattern
// was still loading. Checking a box before that fetch resolved meant
// applyPattern() would silently overwrite it moments later with whatever
// the server actually had - so a day the admin just turned ON could get
// flipped back OFF right before they hit Publish, with no visible sign
// anything had changed. Confirmed root cause of a real incident: several
// days got submitted as "off" this way, which archived an already-active
// day's schedule. The grid (and Publish) is now locked until the load
// finishes, and Publish separately warns by name about any day that's
// currently on and about to be turned off.

(function () {
    'use strict';

    const openBtn = document.getElementById('pbOpenBtn');
    if (!openBtn) return; // tab not present on this page load

    const doctorId = window.rsDoctorId || 0;
    const csrfToken = window.rsCsrfToken || '';

    const backdrop = document.getElementById('pbModalBackdrop');
    const modalError = document.getElementById('pbModalError');
    const fieldFrom = document.getElementById('pbFieldFrom');
    const fieldTo = document.getElementById('pbFieldTo');
    const dayRows = Array.from(document.querySelectorAll('.pb-day-row'));
    const closeBtn = document.getElementById('pbModalClose');
    const cancelBtn = document.getElementById('pbModalCancel');
    const saveBtn = document.getElementById('pbScheduleModalSave');

    const dayNamesByNumber = { 1: 'Monday', 2: 'Tuesday', 3: 'Wednesday', 4: 'Thursday', 5: 'Friday', 6: 'Saturday', 7: 'Sunday' };

    // What get_opd_pattern most recently returned - the source of truth
    // to diff against when Publish is clicked, independent of whatever's
    // currently sitting in the checkboxes.
    let loadedPattern = null;
    let isLoading = false;

    function showModalError(message) {
        if (!modalError) return;
        modalError.textContent = message;
        modalError.classList.toggle('rs-modal-error-visible', !!message);
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

    function setGridLocked(locked) {
        isLoading = locked;
        dayRows.forEach((row) => {
            row.querySelectorAll('input').forEach((input) => {
                input.disabled = locked;
            });
        });
        if (saveBtn) {
            saveBtn.disabled = locked;
            saveBtn.textContent = locked ? 'Loading current pattern…' : 'Publish Schedule';
        }
        fieldFrom.disabled = locked;
        fieldTo.disabled = locked;
    }

    function applyPattern(days) {
        days.forEach((day) => {
            const row = dayRows.find(r => Number(r.dataset.dayOfWeek) === day.day_of_week);
            if (!row) return;
            row.querySelector('.pb-day-enabled').checked = !!day.enabled;
            row.querySelector('.pb-day-start').value = (day.start_time || '08:00:00').slice(0, 5);
            row.querySelector('.pb-day-end').value = (day.end_time || '17:00:00').slice(0, 5);
        });
    }

    async function loadPattern() {
        if (!fieldFrom.value || !doctorId) return;
        setGridLocked(true);
        try {
            const data = await postAction('get_opd_pattern', {
                doctor_id: doctorId,
                as_of_date: fieldFrom.value,
            });
            loadedPattern = data.days;
            applyPattern(data.days);
        } catch (err) {
            showModalError(err.message);
        } finally {
            setGridLocked(false);
        }
    }

    function resetGrid() {
        loadedPattern = null;
        dayRows.forEach((row) => {
            row.querySelector('.pb-day-enabled').checked = false;
            row.querySelector('.pb-day-start').value = '08:00';
            row.querySelector('.pb-day-end').value = '17:00';
        });
    }

    // Last calendar day of whatever month a 'YYYY-MM-DD' string falls
    // in, computed in UTC to avoid local-timezone off-by-one issues.
    function endOfMonth(dateStr) {
        const [y, m] = dateStr.split('-').map(Number);
        return new Date(Date.UTC(y, m, 0)).toISOString().slice(0, 10);
    }

    function openModal() {
        showModalError('');
        resetGrid();
        document.body.classList.add('modal-open');
        backdrop.classList.add('active');
        setGridLocked(true);

        // Default "Period Start" to the day after the doctor's current
        // schedule cleanly ends, if every weekday has a real end date -
        // that's what makes publishing create a genuinely separate new
        // period instead of accidentally splitting a running one. Falls
        // back to today if anything's open-ended or nothing's set up
        // yet, same as before. "Period End" then defaults to the last
        // day of whatever month that start falls in, matching this
        // page's "updated once a month" cadence - still fully editable
        // (clear it for open-ended, or pick a different date for a
        // semi-monthly or other cycle).
        postAction('get_next_period_start', { doctor_id: doctorId })
            .then((data) => {
                fieldFrom.value = data.suggested_start || new Date().toISOString().slice(0, 10);
                fieldTo.value = endOfMonth(fieldFrom.value);
                return loadPattern();
            })
            .catch(() => {
                fieldFrom.value = new Date().toISOString().slice(0, 10);
                fieldTo.value = endOfMonth(fieldFrom.value);
                loadPattern();
            });
    }

    function closeModal() {
        backdrop.classList.remove('active');
        document.body.classList.remove('modal-open');
    }

    openBtn.addEventListener('click', openModal);
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

    // Re-load whatever's currently active whenever the start date
    // changes, so the grid always reflects "what would carry forward if
    // I touched nothing" before the admin starts editing.
    fieldFrom.addEventListener('change', loadPattern);

    if (saveBtn) {
        saveBtn.addEventListener('click', async () => {
            showModalError('');

            if (isLoading) {
                return; // grid is locked mid-load; button is disabled anyway
            }

            if (!fieldFrom.value) {
                showModalError('Please provide a period start date.');
                return;
            }
            if (fieldTo.value && fieldTo.value < fieldFrom.value) {
                showModalError("The period's end date can't be before its start date.");
                return;
            }

            const days = [];
            for (const row of dayRows) {
                const enabled = row.querySelector('.pb-day-enabled').checked;
                const start = row.querySelector('.pb-day-start').value;
                const end = row.querySelector('.pb-day-end').value;

                if (enabled) {
                    if (!start || !end) {
                        showModalError('Please provide both a start and end time for every day marked "On Duty."');
                        return;
                    }
                    if (end <= start) {
                        showModalError('End time must be later than start time for every day marked "On Duty."');
                        return;
                    }
                }

                days.push({
                    day_of_week: Number(row.dataset.dayOfWeek),
                    enabled,
                    start_time: start,
                    end_time: end,
                });
            }

            // Warn by name about any day that's currently ON (per what was
            // actually loaded from the server) and is about to be turned
            // OFF by this publish - this is the exact mistake that caused
            // a previous incident, so it gets called out specifically
            // rather than folded into a generic "nothing is checked"
            // message.
            if (loadedPattern) {
                const turningOff = loadedPattern
                    .filter(d => d.enabled)
                    .filter(d => !days.find(x => x.day_of_week === d.day_of_week && x.enabled))
                    .map(d => dayNamesByNumber[d.day_of_week]);

                if (turningOff.length) {
                    const list = turningOff.join(', ');
                    const ok = confirm(
                        `${list} ${turningOff.length > 1 ? 'are' : 'is'} currently ON DUTY and will be turned OFF by this publish.\n\n` +
                        `If you didn't mean to change ${turningOff.length > 1 ? 'these days' : 'this day'}, click Cancel and reopen "Set Up New Period" to reload the current pattern.\n\n` +
                        `Continue and turn ${turningOff.length > 1 ? 'them' : 'it'} off?`
                    );
                    if (!ok) return;
                }
            }

            if (!days.some(d => d.enabled)) {
                if (!confirm('No day is marked "On Duty" - this doctor will show as off duty for the entire period. Continue?')) {
                    return;
                }
            }

            saveBtn.disabled = true;
            try {
                await postAction('publish_opd_schedule', {
                    doctor_id: doctorId,
                    effective_from: fieldFrom.value,
                    effective_to: fieldTo.value,
                    days_json: JSON.stringify(days),
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

    // Warn before leaving with the modal open and edits pending in it.
    window.addEventListener('beforeunload', (e) => {
        if (backdrop && backdrop.classList.contains('active')) {
            e.preventDefault();
            e.returnValue = '';
        }
    });
})();