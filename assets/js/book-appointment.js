// assets/js/book-appointment.js
//
// Drives the single-page booking form on patient/book-appointment.php.
//
// REWORKED 2026-09-16: department and doctor are no longer patient
// choices, so the old 5-section flow (symptoms -> department -> doctor
// -> slot -> review) is now 3: (1) check symptoms, (2) pick a date &
// time with the auto-assigned doctor, (3) review/confirm. One AJAX call
// (match_symptoms) now does everything sections 2-4 used to do
// separately: match checked symptoms to a department, auto-assign a
// doctor, and return that doctor's slot grid, all at once. If nobody in
// the matched department has real availability, the response comes back
// flagged no_doctors and the flow stops right there - no date/time
// section is ever unlocked.
//
// Hidden inputs left inside #booking-form: doctor_id, slot_start (both
// set by this file as the AJAX/UI responses come in). There is no
// department hidden input anymore - the server re-derives the
// department from the submitted symptoms[] checkboxes themselves on the
// real confirm_booking submit, exactly like the AJAX call already did.
//
// The final "Confirm Booking" button is a real form submit (page reload).
// Everything before that is fetch() calls to book-appointment-actions.php
// which never write to the database - the server re-validates everything
// again on that final submit regardless of what happened here.

const GabayBooking = (function () {
    "use strict";

    let assignedDoctorName = null;

    function csrfToken() {
        return document.querySelector('#booking-form input[name="csrf_token"]').value;
    }

    async function postAction(action, params) {
        const body = new URLSearchParams({ action: action, csrf_token: csrfToken() });
        // symptoms[] needs repeated keys, which URLSearchParams(object)
        // can't express directly - append those separately. Everything
        // else in params (start_date, num_days, etc.) is a plain scalar.
        if (params) {
            Object.keys(params).forEach(function (key) {
                if (key === 'symptoms') {
                    (params.symptoms || []).forEach(function (val) { body.append('symptoms[]', val); });
                } else if (params[key] !== null && params[key] !== undefined && params[key] !== '') {
                    body.append(key, params[key]);
                }
            });
        }
        let response;
        try {
            response = await fetch('book-appointment-actions.php', { method: 'POST', body: body });
        } catch (err) {
            return { success: false, error: 'Network error - please check your connection and try again.' };
        }
        try {
            return await response.json();
        } catch (err) {
            return { success: false, error: 'Something went wrong reading the server response.' };
        }
    }

    function setLocked(sectionNum, locked) {
        const section = document.getElementById('section-' + sectionNum);
        if (!section) return;
        section.classList.toggle('booking-section-locked', locked);
    }

    // Keeps the sticky right-hand summary panel in sync with the form -
    // purely cosmetic, so it's safe to fail silently if a summary
    // element isn't on the page.
    function setSummaryStep(stepNum, complete, value) {
        const step = document.getElementById('summary-step-' + stepNum);
        const valueEl = document.getElementById('summary-value-' + stepNum);
        if (!step || !valueEl) return;
        step.classList.toggle('is-complete', complete);
        if (value !== undefined) valueEl.textContent = value;
    }

    const summaryDefaults = {
        1: 'Not selected yet',
        2: 'Not matched yet',
        3: 'Not selected yet',
    };

    function resetSummaryFrom(fromStep) {
        for (let n = fromStep; n <= 3; n++) {
            setSummaryStep(n, false, summaryDefaults[n]);
        }
    }

    // Resets sections at/after `fromSection` back to their locked, empty
    // placeholder state - used whenever the checked symptoms change
    // (any earlier choice changing invalidates whatever slot/review had
    // already been built further down the page).
    function resetFrom(fromSection) {
        const placeholders = {
            2: '<p class="empty-hint">Check your symptoms above to continue.</p>',
            3: '<p class="empty-hint">Pick a date and time above to review your booking.</p>',
        };
        for (let n = fromSection; n <= 3; n++) {
            setLocked(n, true);
            const contentId = { 2: 'slot-content', 3: 'review-content' }[n];
            if (contentId && placeholders[n]) {
                document.getElementById(contentId).innerHTML = placeholders[n];
            }
        }
        if (fromSection <= 2) document.getElementById('doctor_id_input').value = '';
        if (fromSection <= 3) document.getElementById('slot_start_input').value = '';
        resetSummaryFrom(fromSection);
    }

    function getCheckedSymptoms() {
        return Array.from(document.querySelectorAll('input[name="symptoms[]"]:checked')).map(function (el) {
            return el.value;
        });
    }

    // "When would you like to be seen?" (2026-09-17, advance booking) -
    // keeps the hidden start_date/num_days inputs matched to whichever
    // radio (+ month <select> if "advance" is picked) is currently
    // selected. Called on every change AND once on page load, so a page
    // loaded via dashboard.php's "Advance Booking" link (?mode=advance,
    // which only pre-checks the "advance" radio server-side - see
    // book-appointment.php's $advance_mode comment) still ends up with
    // populated hidden inputs even before the patient touches anything.
    function onWhenChanged() {
        const advanceRadio = document.getElementById('when-advance');
        const startInput = document.getElementById('start_date_input');
        const numDaysInput = document.getElementById('num_days_input');
        const monthWrapper = document.getElementById('advance-month-wrapper');
        const isAdvance = advanceRadio && advanceRadio.checked;

        monthWrapper.style.display = isAdvance ? '' : 'none';

        if (isAdvance) {
            const select = document.getElementById('advance-month-select');
            const opt = select.options[select.selectedIndex];
            startInput.value = opt ? opt.value : '';
            numDaysInput.value = opt ? opt.getAttribute('data-num-days') : '';
        } else {
            startInput.value = '';
            numDaysInput.value = '';
        }

        // Any earlier match is now stale (different window) - same
        // "changing an earlier choice invalidates what came after"
        // pattern as onSymptomsChanged() below.
        resetFrom(2);
    }

    // Called on every checkbox change - just gates the Continue button
    // and clears any stale error/downstream state. Nothing is fetched
    // until the patient actually clicks Continue.
    function onSymptomsChanged() {
        const checked = getCheckedSymptoms();
        document.getElementById('continue-btn').disabled = checked.length === 0;
        document.getElementById('symptom-error').textContent = '';
        resetFrom(2);
    }

    // ------------------------------------------------------------
    // Section 1 -> 2: checked symptoms -> department match -> auto-
    // assigned doctor -> that doctor's slot grid, all in one call.
    // ------------------------------------------------------------
    async function matchSymptoms() {
        const btn = document.getElementById('continue-btn');
        const errorEl = document.getElementById('symptom-error');
        const symptoms = getCheckedSymptoms();

        errorEl.textContent = '';

        if (symptoms.length === 0) {
            errorEl.textContent = 'Please check at least one symptom that applies to you.';
            return;
        }

        btn.disabled = true;
        btn.textContent = 'Checking...';

        const data = await postAction('match_symptoms', {
            symptoms: symptoms,
            start_date: document.getElementById('start_date_input').value,
            num_days: document.getElementById('num_days_input').value,
        });

        btn.disabled = false;
        btn.textContent = 'Continue';

        if (!data.success) {
            errorEl.textContent = data.error || 'Something went wrong. Please try again.';
            resetFrom(2);
            return;
        }

        const summaryLabel = (data.matched_labels || []).join(', ');
        setSummaryStep(1, true, summaryLabel.length > 60 ? summaryLabel.slice(0, 60) + '...' : summaryLabel);

        if (data.no_doctors) {
            renderNoDoctorsAvailable(data.message);
            return;
        }

        document.getElementById('doctor_id_input').value = data.doctor_id;
        assignedDoctorName = data.doctor_name;
        setSummaryStep(2, true, 'Dr. ' + assignedDoctorName);

        renderSlotGrid(data.grid, data.doctor_name);
        setLocked(2, false);
    }

    // Terminal state - no doctors currently available for the matched
    // department (either none exist, or every one is fully booked over
    // the whole booking window). Section 2 is replaced with this
    // message instead of ever unlocking a date/time grid; section 3
    // stays locked. The patient can still uncheck/recheck symptoms and
    // try again (e.g. if they checked the wrong box), which is why this
    // doesn't disable the checkboxes themselves.
    //
    // If this happened on the normal "soonest available" window (not
    // already looking at a specific later month), offer a one-click way
    // to retry against next month instead of making the patient scroll
    // back up, switch the radio, and click Continue again themselves.
    function renderNoDoctorsAvailable(message) {
        resetFrom(2);
        const onSoonestWindow = document.getElementById('when-soonest').checked;
        const tryLaterBtn = onSoonestWindow
            ? '<button type="button" class="btn btn-secondary" style="margin-top:12px;" onclick="GabayBooking.tryNextMonth()">Check availability next month instead</button>'
            : '';
        document.getElementById('slot-content').innerHTML =
            '<div class="empty-state"><p class="empty-state-text">' + escapeHtml(message || 'No doctors are currently available for this. Please contact the front desk.') + '</p>' + tryLaterBtn + '</div>';
        setLocked(2, false);
    }

    // Switches the "when" picker to "advance" (defaulting to whichever
    // month is first in the list) and re-runs the match immediately -
    // the one-click follow-up offered by renderNoDoctorsAvailable above.
    function tryNextMonth() {
        document.getElementById('when-advance').checked = true;
        onWhenChanged();
        matchSymptoms();
    }

    function renderSlotGrid(data, doctorName) {
        const container = document.getElementById('slot-content');
        const serverNow = new Date(data.server_now.replace(' ', 'T'));

        let header = '<tr><th>Time</th>';
        data.days.forEach(function (day) {
            const cls = day.on_duty ? '' : ' class="day-off-header"';
            let sub = '';
            if (!day.on_duty) sub = '<div class="day-off-label">Day Off</div>';
            else if (day.full) sub = '<div class="day-off-label">Fully Booked</div>';
            header += '<th' + cls + '>' + escapeHtml(day.label) + sub + '</th>';
        });
        header += '</tr>';

        let bodyRows = '';
        data.clinic_hours.forEach(function (hourStr, idx) {
            const hourLabel = formatHourLabel(hourStr);
            let row = '<tr><td class="slot-hour-label">' + hourLabel + '</td>';

            data.days.forEach(function (day) {
                const slotDatetime = day.date + ' ' + hourStr + ':00';
                const isBooked = data.booked_slots.indexOf(slotDatetime) !== -1;
                const slotTime = new Date(slotDatetime.replace(' ', 'T'));
                const isPast = serverNow.getTime() >= (slotTime.getTime() + 30 * 60 * 1000);

                let withinDutyHours = true;
                if (day.on_duty && day.start && day.end) {
                    const slotTimeOnly = hourStr + ':00';
                    withinDutyHours = (slotTimeOnly >= day.start && slotTimeOnly < day.end);
                }

                const cellCls = day.on_duty ? '' : ' class="day-off-cell"';

                if (!day.on_duty) {
                    row += '<td' + cellCls + '><button type="button" class="slot-btn slot-disabled" disabled title="Doctor not on duty this day"></button></td>';
                } else if (day.full) {
                    row += '<td' + cellCls + '><button type="button" class="slot-btn slot-disabled" disabled title="Doctor is fully booked this day"></button></td>';
                } else if (!withinDutyHours) {
                    row += '<td' + cellCls + '><button type="button" class="slot-btn slot-disabled" disabled title="Outside doctor\'s duty hours this day"></button></td>';
                } else if (isBooked || isPast) {
                    row += '<td' + cellCls + '><button type="button" class="slot-btn slot-disabled" disabled></button></td>';
                } else {
                    row += '<td' + cellCls + '><button type="button" class="slot-btn" data-datetime="' + slotDatetime + '"></button></td>';
                }
            });

            row += '</tr>';
            bodyRows += row;

            // Detects the actual schedule gap (lunch) rather than
            // hardcoding a boundary hour: the backend
            // (includes/slot_grid.php) already omits 11:00-13:00 from
            // clinic_hours entirely, so wherever the jump to the next
            // slot exceeds a normal slot duration is the real gap.
            const nextHourStr = data.clinic_hours[idx + 1];
            if (nextHourStr) {
                const toMinutes = function (hhmm) {
                    const parts = hhmm.split(':').map(Number);
                    return parts[0] * 60 + parts[1];
                };
                const gapMinutes = toMinutes(nextHourStr) - toMinutes(hourStr);
                if (gapMinutes >= 90) {
                    bodyRows += '<tr class="lunch-break-row"><td class="slot-hour-label">11:00 AM&ndash;1:00 PM</td>' +
                        '<td colspan="' + data.days.length + '" class="lunch-break-cell">Lunch Break</td></tr>';
                }
            }
        });

        container.innerHTML =
            '<p class="booking-label">You\'ve been matched with Dr. ' + escapeHtml(doctorName) + '. Choose a date and time:</p>' +
            '<div class="slot-grid-wrapper"><table class="slot-grid"><thead>' + header + '</thead><tbody>' + bodyRows + '</tbody></table></div>' +
            '<p class="slot-grid-legend">' +
            '<span class="legend-item"><span class="legend-dot legend-available"></span> Available</span>' +
            '<span class="legend-item"><span class="legend-dot legend-booked"></span> Booked</span>' +
            '<span class="legend-item"><span class="legend-dot legend-dayoff"></span> Day Off</span>' +
            '</p>' +
            '<p class="slot-selected-label" id="slot-selected-label">No time selected yet.</p>';

        container.querySelectorAll('.slot-btn:not(.slot-disabled)').forEach(function (btn) {
            btn.addEventListener('click', function () {
                container.querySelectorAll('.slot-btn').forEach(function (b) { b.classList.remove('slot-selected'); });
                btn.classList.add('slot-selected');
                const datetime = btn.getAttribute('data-datetime');
                document.getElementById('slot_start_input').value = datetime;

                const d = new Date(datetime.replace(' ', 'T'));
                const label = d.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' }) +
                    ' at ' + d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
                document.getElementById('slot-selected-label').textContent = 'Selected: ' + label;

                selectSlot(datetime, label);
            });
        });
    }

    // ------------------------------------------------------------
    // Section 2 -> 3: selected slot -> review & confirm
    // ------------------------------------------------------------
    function selectSlot(datetime, label) {
        const container = document.getElementById('review-content');
        const patientName = document.body.getAttribute('data-patient-name') || '';
        const symptomLabels = Array.from(document.querySelectorAll('input[name="symptoms[]"]:checked')).map(function (el) {
            return el.parentElement.querySelector('span').textContent;
        });
        setSummaryStep(3, true, label);

        container.innerHTML =
            '<p class="booking-label">Please review your appointment details:</p>' +
            '<div class="confirmation-summary">' +
            '<div class="confirmation-row"><span class="confirmation-key">Patient</span><span class="confirmation-value">' + escapeHtml(patientName) + '</span></div>' +
            '<div class="confirmation-row"><span class="confirmation-key">Reason for visit</span><span class="confirmation-value">' + escapeHtml(symptomLabels.join(', ')) + '</span></div>' +
            '<div class="confirmation-row"><span class="confirmation-key">Doctor</span><span class="confirmation-value">Dr. ' + escapeHtml(assignedDoctorName || '') + '</span></div>' +
            '<div class="confirmation-row"><span class="confirmation-key">Date &amp; Time</span><span class="confirmation-value">' + escapeHtml(label) + '</span></div>' +
            '</div>' +
            '<div class="policy-notice">' +
            'Please arrive on time. If you\'re more than <strong>30 minutes late</strong>, your slot may be given to a walk-in patient and marked as a no-show. ' +
            'After <strong>3 no-shows</strong>, your account will be temporarily blocked.' +
            '</div>' +
            '<button type="submit" class="btn btn-primary">Confirm Booking</button>';

        setLocked(3, false);
        container.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    // FIXED 2026-08-09: clinic_hours are "HH:MM" strings (per-department
    // slot durations aren't always a clean 60 minutes), so this needs to
    // split on ':' rather than treat the value as a plain hour integer.
    function formatHourLabel(hourStr) {
        const [h, m] = hourStr.split(':').map(Number);
        const d = new Date();
        d.setHours(h, m, 0, 0);
        return d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
    }

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = String(str == null ? '' : str);
        return div.innerHTML;
    }

    document.addEventListener('DOMContentLoaded', function () {
        const continueBtn = document.getElementById('continue-btn');
        if (continueBtn) continueBtn.disabled = getCheckedSymptoms().length === 0;
        if (document.getElementById('when-soonest')) onWhenChanged();
    });

    return {
        onSymptomsChanged: onSymptomsChanged,
        onWhenChanged: onWhenChanged,
        matchSymptoms: matchSymptoms,
        tryNextMonth: tryNextMonth,
    };
})();