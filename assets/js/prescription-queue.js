// assets/js/prescription-queue.js
// Drives staff/prescription-queue.php's Accept panel. All group/line data
// is already embedded server-side as prescriptionQueueData (see that
// file's header comment on why - same reasoning as lab-orders.js) - this
// file only ever fetches to actually dispense or void a line, both
// straight through to staff/dispense-actions.php, completely unchanged
// by the 2026-09-17 rework that removed dispense-stock.php.

(function () {
    "use strict";

    var overlay = document.getElementById("pqPanelOverlay");
    var panel = document.getElementById("pqPanel");
    var patientEl = document.getElementById("pqPatient");
    var doctorEl = document.getElementById("pqDoctor");
    var diagnosisEl = document.getElementById("pqDiagnosis");
    var linesBody = document.getElementById("pqLinesBody");
    var errorEl = document.getElementById("pqError");
    var pqBlockedNotice = document.getElementById("pqBlockedNotice");
    var releaseBtn = document.getElementById("pqReleaseBtn");
    var closeBtn = document.getElementById("pqCloseBtn");

    var voidOverlay = document.getElementById("pqVoidOverlay");
    var voidMedicineNameEl = document.getElementById("pqVoidMedicineName");
    var voidReasonInput = document.getElementById("pqVoidReasonInput");
    var voidError = document.getElementById("pqVoidError");
    var voidCancelBtn = document.getElementById("pqVoidCancelBtn");
    var voidConfirmBtn = document.getElementById("pqVoidConfirmBtn");

    var currentGroup = null;
    var voidTargetLine = null;

    function findGroup(groupKey) {
        for (var i = 0; i < prescriptionQueueData.length; i++) {
            if (prescriptionQueueData[i].group_key === groupKey) {
                return prescriptionQueueData[i];
            }
        }
        return null;
    }

    function openPanel(groupKey) {
        var group = findGroup(groupKey);
        if (!group) {
            console.error("Prescription queue: no group found for " + groupKey);
            return;
        }
        currentGroup = group;
        errorEl.hidden = true;

        patientEl.textContent = group.patient_name;
        doctorEl.textContent = group.doctor_name;
        diagnosisEl.textContent = group.diagnosis;

        renderLines(group);

        overlay.classList.add("panel-visible");
        document.body.style.overflow = "hidden";
    }

    function closePanel() {
        overlay.classList.remove("panel-visible");
        document.body.style.overflow = "";
        currentGroup = null;
    }

    function renderLines(group) {
        linesBody.innerHTML = "";
        var hasDispensable = false;
        var hasBlocked = false;

        group.lines.forEach(function (line) {
            if (line.blocked_reason) hasBlocked = true;
            var row = document.createElement("tr");

            var nameCell = document.createElement("td");
            nameCell.textContent = line.medicine_name;
            row.appendChild(nameCell);

            var qtyCell = document.createElement("td");
            qtyCell.textContent = line.quantity_label;
            row.appendChild(qtyCell);

            var instrCell = document.createElement("td");
            instrCell.textContent = line.instructions ? line.instructions : "—";
            row.appendChild(instrCell);

            var stockCell = document.createElement("td");
            if (line.blocked_reason) {
                var span = document.createElement("span");
                span.style.color = "#b91c1c";
                span.textContent = line.blocked_reason;
                stockCell.appendChild(span);
            } else {
                stockCell.textContent = line.current_stock + " " + line.unit + "(s)";
            }
            row.appendChild(stockCell);

            var actionCell = document.createElement("td");
            if (line.medicine_id === null) {
                // Not in the pharmacy catalog at all - the only case
                // dispense-actions.php's void endpoints will actually
                // accept (see their own "medicine_id !== null" check).
                var voidBtn = document.createElement("button");
                voidBtn.type = "button";
                voidBtn.className = "btn btn-sm rx-void-btn";
                voidBtn.textContent = "Mark as Void";
                voidBtn.addEventListener("click", function () {
                    openVoidModal(line);
                });
                actionCell.appendChild(voidBtn);
            } else if (line.blocked_reason) {
                // Linked to the catalog but out of stock - nothing to do
                // from this screen (that's a restock problem, not a void
                // one); a disabled input just shows there's no input to
                // give.
                var disabledInput = document.createElement("input");
                disabledInput.type = "number";
                disabledInput.disabled = true;
                disabledInput.style.width = "80px";
                actionCell.appendChild(disabledInput);
            } else {
                hasDispensable = true;
                var input = document.createElement("input");
                input.type = "number";
                input.min = "1";
                input.step = "1";
                input.style.width = "80px";
                input.className = "pq-pieces-input";
                input.dataset.prescriptionId = line.prescription_id || "";
                input.dataset.dischargeMedicationId = line.discharge_medication_id || "";
                input.dataset.medicineId = line.medicine_id;
                input.dataset.maxStock = line.current_stock;
                if (line.parsed_qty !== null) input.value = line.parsed_qty;
                actionCell.appendChild(input);
            }
            row.appendChild(actionCell);

            linesBody.appendChild(row);
        });

        // FIXED 2026-09-18: found while reviewing this panel against
        // dispense-actions.php's actual validation - dispense_prescription/
        // dispense_confinement_medications both reject the request unless
        // EVERY still-pending line for this visit is present in the
        // submitted items, not just the ones being dispensed right now
        // (see that file's "count(...) !== count(...)" checks). A
        // dispensable line sitting alongside an out-of-stock one would
        // always fail Release with a confusing "this has changed since it
        // was loaded" error, since the out-of-stock line never gets an
        // input to submit at all. Release is now only offered when EVERY
        // line is actually dispensable - if any line is blocked, a notice
        // explains what needs to happen first instead of offering a button
        // that's guaranteed to fail.
        releaseBtn.style.display = (hasDispensable && !hasBlocked) ? "" : "none";

        if (hasBlocked) {
            var hasUnlinked = group.lines.some(function (l) { return l.medicine_id === null; });
            var hasOutOfStock = group.lines.some(function (l) { return l.medicine_id !== null && l.blocked_reason; });
            var parts = [];
            if (hasUnlinked) parts.push("void the line(s) not in the pharmacy catalog");
            if (hasOutOfStock) parts.push("restock the out-of-stock medicine(s) in Inventory");
            pqBlockedNotice.textContent = "This can't be released yet — " + parts.join(", and ") + " first.";
            pqBlockedNotice.hidden = false;
        } else {
            pqBlockedNotice.hidden = true;
        }
    }

    function openVoidModal(line) {
        voidTargetLine = line;
        voidMedicineNameEl.textContent = line.medicine_name;
        voidError.hidden = true;
        voidConfirmBtn.disabled = false;
        voidConfirmBtn.textContent = "Void This Line";
        voidOverlay.classList.add("panel-visible");
    }

    function closeVoidModal() {
        voidOverlay.classList.remove("panel-visible");
        voidTargetLine = null;
    }

    releaseBtn.addEventListener("click", function () {
        errorEl.hidden = true;

        var items = [];
        var invalid = false;
        linesBody.querySelectorAll(".pq-pieces-input").forEach(function (input) {
            var pieces = parseInt(input.value, 10);
            if (isNaN(pieces) || pieces <= 0) {
                invalid = true;
                return;
            }
            if (currentGroup.type === "confinement") {
                items.push({
                    discharge_medication_id: parseInt(input.dataset.dischargeMedicationId, 10),
                    medicine_id: parseInt(input.dataset.medicineId, 10),
                    pieces: pieces,
                });
            } else {
                items.push({
                    prescription_id: parseInt(input.dataset.prescriptionId, 10),
                    medicine_id: parseInt(input.dataset.medicineId, 10),
                    pieces: pieces,
                });
            }
        });

        if (invalid || items.length === 0) {
            errorEl.textContent = "Enter a valid piece count for every medicine before dispensing.";
            errorEl.hidden = false;
            return;
        }

        releaseBtn.disabled = true;
        releaseBtn.textContent = "Dispensing…";

        var bodyFields = currentGroup.type === "confinement" ? {
            action: "dispense_confinement_medications",
            csrf_token: csrfToken,
            confinement_id: currentGroup.confinement_id,
            reference_number: currentGroup.reference,
            items: JSON.stringify(items),
        } : {
            action: "dispense_prescription",
            csrf_token: csrfToken,
            appointment_id: currentGroup.appointment_id,
            reference_number: currentGroup.reference,
            consultation_id: currentGroup.consultation_id,
            items: JSON.stringify(items),
        };

        fetch("dispense-actions.php", {
                method: "POST",
                body: new URLSearchParams(bodyFields),
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (!data.success) {
                    releaseBtn.disabled = false;
                    releaseBtn.textContent = "Release";
                    errorEl.textContent = data.error || "Something went wrong. Please try again.";
                    errorEl.hidden = false;
                    return;
                }
                window.location.reload();
            })
            .catch(function () {
                releaseBtn.disabled = false;
                releaseBtn.textContent = "Release";
                errorEl.textContent = "Could not reach the server. Please check your connection and try again.";
                errorEl.hidden = false;
            });
    });

    voidCancelBtn.addEventListener("click", closeVoidModal);
    voidOverlay.addEventListener("click", function (e) {
        if (e.target === voidOverlay) closeVoidModal();
    });

    voidConfirmBtn.addEventListener("click", function () {
        if (!voidTargetLine) return;

        // No reason field -- "Mark as Void" only ever renders for a line
        // that isn't in the pharmacy catalog to begin with (see the
        // medicine_id === null branch in renderLines above), and the
        // modal copy in prescription-queue.php already says so. A typed
        // reason would just restate what the system already knows.
        voidError.hidden = true;
        voidConfirmBtn.disabled = true;
        voidConfirmBtn.textContent = "Voiding…";

        var bodyFields = currentGroup.type === "confinement" ? {
            action: "void_confinement_medication_line",
            csrf_token: csrfToken,
            confinement_id: currentGroup.confinement_id,
            reference_number: currentGroup.reference,
            discharge_medication_id: voidTargetLine.discharge_medication_id,
        } : {
            action: "void_prescription_line",
            csrf_token: csrfToken,
            appointment_id: currentGroup.appointment_id,
            reference_number: currentGroup.reference,
            prescription_id: voidTargetLine.prescription_id,
        };

        fetch("dispense-actions.php", {
                method: "POST",
                body: new URLSearchParams(bodyFields),
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (!data.success) {
                    voidConfirmBtn.disabled = false;
                    voidConfirmBtn.textContent = "Void This Line";
                    voidError.textContent = data.error || "Something went wrong. Please try again.";
                    voidError.hidden = false;
                    return;
                }
                window.location.reload();
            })
            .catch(function () {
                voidConfirmBtn.disabled = false;
                voidConfirmBtn.textContent = "Void This Line";
                voidError.textContent = "Could not reach the server. Please check your connection and try again.";
                voidError.hidden = false;
            });
    });

    closeBtn.addEventListener("click", closePanel);
    overlay.addEventListener("click", function (e) {
        if (e.target === overlay) closePanel();
    });

    document.querySelectorAll(".pq-accept-btn").forEach(function (btn) {
        btn.addEventListener("click", function () {
            openPanel(btn.dataset.groupKey);
        });
    });
})();