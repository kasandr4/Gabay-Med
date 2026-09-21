// assets/js/doctor-schedule-reschedule.js
// GabayMed — Doctor Portal: My Schedule page, "Reschedule" modal in the
// Needs Follow-Up panel. Lets a doctor move one of their OWN orphaned
// appointments to a new slot on their own calendar, talking to
// doctor/my-schedule-actions.php's get_slots / reschedule actions.
//
// Doctor-scoped twin of assets/js/staff-schedule-reschedule.js - same
// slot-grid rendering (copied rather than shared for the same reason
// noted there: the patient booking wizard's renderer is wired to
// patient-only DOM). The one real difference: there's no doctor picker
// here at all, not even via data attribute - doctor/my-schedule-actions.php
// always resolves "which doctor" from the logged-in session, the same
// ownership guarantee the Update Availability modal already relies on.

(function () {
    'use strict';

    const backdrop = document.getElementById('msRescheduleModalBackdrop');
    if (!backdrop) return;

    const csrfToken = window.msCsrfToken || '';
    const modalError = document.getElementById('msRescheduleModalError');
    const modalContext = document.getElementById('msRescheduleModalContext');
    const slotContent = document.getElementById('ms-slot-content');
    const confirmBtn = document.getElementById('msRescheduleModalConfirm');
    const closeBtn = document.getElementById('msRescheduleModalClose');
    const cancelBtn = document.getElementById('msRescheduleModalCancel');
    const reasonSelect = document.getElementById('msRescheduleReason');
    const reasonOtherWrap = document.getElementById('msRescheduleReasonOtherWrap');
    const reasonOtherInput = document.getElementById('msRescheduleReasonOther');

    let activeAppointmentId = null;
    let selectedSlotStart = null;

    /**
     * Returns the doctor's chosen reason (a suggested option, or the
     * free-text "Other" value), or '' if none has been provided yet.
     * Required before a reschedule can be submitted - see confirmBtn's
     * click handler below.
     */
    function getReasonValue() {
        if (!reasonSelect) return '';
        const selected = reasonSelect.value;
        if (selected === 'Other') {
            return (reasonOtherInput.value || '').trim();
        }
        return selected;
    }

    /**
     * The reschedule modal's suggested reasons are a fixed, curated list
     * (Emergency Leave / Medical Emergency / Hospital Meeting / Personal
     * Leave / Other) - deliberately NOT the same enum as
     * doctor_schedule_overrides.status (which has half_day/training/
     * unavailable/etc, meant for the availability calendar, not for a
     * patient-facing message). This maps the override status that
     * orphaned this appointment onto the closest matching suggested
     * reason, so the doctor isn't retyping something they just told
     * Update Availability - but it's still just a prefill, not a
     * shortcut around the requirement: the field stays editable, and an
     * unmapped status (half_day/training/unavailable) falls back to
     * "Other" with the override's own free-text reason dropped into the
     * specify box, rather than guessing.
     */
    const OVERRIDE_STATUS_TO_REASON = {
        emergency_leave: 'Emergency Leave',
        meeting: 'Hospital Meeting',
    };

    function prefillReasonFromOverride(overrideStatus, overrideReason) {
        if (!reasonSelect) return;

        const mapped = OVERRIDE_STATUS_TO_REASON[overrideStatus];
        if (mapped) {
            reasonSelect.value = mapped;
            reasonOtherWrap.style.display = 'none';
            reasonOtherInput.value = '';
        } else {
            reasonSelect.value = 'Other';
            reasonOtherWrap.style.display = '';
            reasonOtherInput.value = overrideReason || '';
        }
    }

    if (reasonSelect) {
        reasonSelect.addEventListener('change', function () {
            const isOther = reasonSelect.value === 'Other';
            reasonOtherWrap.style.display = isOther ? '' : 'none';
            if (!isOther) reasonOtherInput.value = '';
        });
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

    function showModalError(message) {
        modalError.textContent = message;
        modalError.classList.toggle('rs-modal-error-visible', !!message);
    }

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // Same fix and reasoning as assets/js/book-appointment.js's
    // formatHourLabel — clinic_hours is "HH:MM" strings, not integer
    // hours, since the PER-DEPARTMENT DURATION change in
    // includes/slot_grid.php. This modal has the same broken pattern.
    function formatHourLabel(hourStr) {
        const [h, m] = hourStr.split(':').map(Number);
        const d = new Date();
        d.setHours(h, m, 0, 0);
        return d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
    }

    /* ========================== Slot grid render ========================= */

    function renderSlotGrid(data) {
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
                    row += '<td' + cellCls + '><button type="button" class="slot-btn slot-disabled" disabled title="Not on duty this day"></button></td>';
                } else if (day.full) {
                    row += '<td' + cellCls + '><button type="button" class="slot-btn slot-disabled" disabled title="Fully booked this day"></button></td>';
                } else if (!withinDutyHours) {
                    row += '<td' + cellCls + '><button type="button" class="slot-btn slot-disabled" disabled title="Outside your duty hours this day"></button></td>';
                } else if (isBooked || isPast) {
                    row += '<td' + cellCls + '><button type="button" class="slot-btn slot-disabled" disabled></button></td>';
                } else {
                    row += '<td' + cellCls + '><button type="button" class="slot-btn" data-datetime="' + slotDatetime + '"></button></td>';
                }
            });

            row += '</tr>';
            bodyRows += row;

            // Same fix and reasoning as book-appointment.js: detect the
            // actual schedule gap instead of hardcoding hourStr ===
            // '10:00', which stranded later AM slots (e.g. 10:30 on a
            // 30-min duration) below the lunch break row.
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

        slotContent.innerHTML =
            '<p class="booking-label">Choose a new date and time on your schedule:</p>' +
            '<div class="slot-grid-wrapper"><table class="slot-grid"><thead>' + header + '</thead><tbody>' + bodyRows + '</tbody></table></div>' +
            '<p class="slot-grid-legend">' +
            '<span class="legend-item"><span class="legend-dot legend-available"></span> Available</span>' +
            '<span class="legend-item"><span class="legend-dot legend-booked"></span> Booked</span>' +
            '<span class="legend-item"><span class="legend-dot legend-dayoff"></span> Day Off</span>' +
            '</p>' +
            '<p class="slot-selected-label" id="ms-slot-selected-label">No time selected yet.</p>';

        slotContent.querySelectorAll('.slot-btn:not(.slot-disabled)').forEach(function (btn) {
            btn.addEventListener('click', function () {
                slotContent.querySelectorAll('.slot-btn').forEach(function (b) { b.classList.remove('slot-selected'); });
                btn.classList.add('slot-selected');
                const datetime = btn.getAttribute('data-datetime');
                selectedSlotStart = datetime;

                const d = new Date(datetime.replace(' ', 'T'));
                const label = d.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' }) +
                    ' at ' + d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
                document.getElementById('ms-slot-selected-label').textContent = 'Selected: ' + label;

                confirmBtn.disabled = false;
            });
        });
    }

    /* ============================ Modal open/close ========================= */

    function openModal(btn) {
        activeAppointmentId = btn.dataset.appointmentId;
        selectedSlotStart = null;
        confirmBtn.disabled = true;
        showModalError('');

        modalContext.textContent = 'Moving ' + btn.dataset.patientName + "'s appointment (currently " + btn.dataset.oldTime + ').';
        slotContent.innerHTML = '<p class="empty-hint">Loading open slots...</p>';

        if (reasonSelect) {
            prefillReasonFromOverride(btn.dataset.overrideStatus || '', btn.dataset.overrideReason || '');
        }

        document.body.classList.add('modal-open');
        backdrop.classList.add('active');

        postAction('get_slots', {})
            .then(renderSlotGrid)
            .catch(function (err) {
                slotContent.innerHTML = '';
                showModalError(err.message);
            });
    }

    function closeModal() {
        backdrop.classList.remove('active');
        document.body.classList.remove('modal-open');
        activeAppointmentId = null;
        selectedSlotStart = null;
    }

    document.querySelectorAll('.ms-reschedule-btn').forEach(function (btn) {
        btn.addEventListener('click', function () { openModal(btn); });
    });

    if (closeBtn) closeBtn.addEventListener('click', closeModal);
    if (cancelBtn) cancelBtn.addEventListener('click', closeModal);
    backdrop.addEventListener('click', function (e) {
        if (e.target === backdrop) closeModal();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && backdrop.classList.contains('active')) closeModal();
    });

    if (confirmBtn) {
        confirmBtn.addEventListener('click', function () {
            if (!activeAppointmentId || !selectedSlotStart) return;

            const reason = getReasonValue();
            if (!reason) {
                showModalError('Please select or enter a reason for rescheduling.');
                return;
            }

            showModalError('');
            confirmBtn.disabled = true;

            postAction('reschedule', { appointment_id: activeAppointmentId, slot_start: selectedSlotStart, reason: reason })
                .then(function () {
                    closeModal();
                    window.location.reload();
                })
                .catch(function (err) {
                    showModalError(err.message);
                    confirmBtn.disabled = false;
                });
        });
    }
})();