// prescriptions.js
// GabayMed — Patient Prescriptions page
// Populates and controls the "View Details" modal using prescriptionsData,
// which is rendered server-side in prescriptions.php and already scoped
// to the logged-in patient only. No AJAX calls needed.
//
// FIXED 2026-09-20: status label text (Pending/Dispensed/Void) now reads
// from window.rxStatusLabels, embedded by prescriptions.php from
// includes/status_labels.php's get_rx_status_labels() - was previously
// hardcoded here too, a second copy of the same three words that had
// already drifted once between the staff and patient sides for the
// lab-status equivalent (see lab-orders.js). The inline fallback is only
// for the case prescriptions.php somehow doesn't embed the global at all,
// not a second source of truth to keep in sync by hand.

document.addEventListener("DOMContentLoaded", function () {
    initPrescriptionModal();
});

function initPrescriptionModal() {
    var overlay = document.getElementById("rxModalOverlay");
    var closeBtn = document.getElementById("rxModalClose");
    var doctorEl = document.getElementById("rxDetailDoctor");
    var dateEl = document.getElementById("rxDetailDate");
    var diagnosisEl = document.getElementById("rxDetailDiagnosis");
    var notesEl = document.getElementById("rxDetailNotes");
    var medicinesListEl = document.getElementById("rxMedicinesList");

    // Fail loudly instead of silently: if any expected element or the
    // data payload is missing, log exactly what's missing so it shows
    // up in the browser console instead of just "nothing happens".
    var missing = [];
    if (!overlay) missing.push("#rxModalOverlay");
    if (!closeBtn) missing.push("#rxModalClose");
    if (!doctorEl) missing.push("#rxDetailDoctor");
    if (!dateEl) missing.push("#rxDetailDate");
    if (!diagnosisEl) missing.push("#rxDetailDiagnosis");
    if (!notesEl) missing.push("#rxDetailNotes");
    if (!medicinesListEl) missing.push("#rxMedicinesList");
    if (typeof prescriptionsData === "undefined") missing.push("prescriptionsData");

    if (missing.length > 0) {
        console.error("Prescriptions modal: missing " + missing.join(", ") + ". Check that prescriptions.php markup matches prescriptions.js.");
        return;
    }

    function findRecord(source, id) {
        for (var i = 0; i < prescriptionsData.length; i++) {
            if (prescriptionsData[i].source === source && prescriptionsData[i].id === id) {
                return prescriptionsData[i];
            }
        }
        return null;
    }

    function openModal(source, id) {
        var record = findRecord(source, id);
        if (!record) {
            console.error("Prescriptions modal: no record found for " + source + " id " + id);
            return;
        }

        doctorEl.textContent = record.doctor_name || "—";
        dateEl.textContent = record.date || "—";
        diagnosisEl.textContent = record.diagnosis ? record.diagnosis : "Not available";
        notesEl.textContent = record.notes ? record.notes : "No additional notes.";

        medicinesListEl.innerHTML = "";
        record.medicines.forEach(function (med) {
            var card = document.createElement("div");
            card.className = "rx-medicine-card";

            var header = document.createElement("div");
            header.className = "rx-medicine-card-header";

            var icon = document.createElement("span");
            icon.className = "rx-medicine-icon";
            icon.innerHTML = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.5 20.5 3.5 13.5a4.95 4.95 0 1 1 7-7l7 7a4.95 4.95 0 1 1-7 7Z"></path><path d="m8.5 8.5 7 7"></path></svg>';

            var name = document.createElement("span");
            name.className = "rx-medicine-name";
            name.textContent = med.name;

            header.appendChild(icon);
            header.appendChild(name);

            var chips = document.createElement("div");
            chips.className = "rx-medicine-chips";

            var statusLabels = window.rxStatusLabels || { pending: "Pending", dispensed: "Dispensed", void: "Void" };
            var statusChip = document.createElement("span");
            statusChip.className = "rx-chip rx-chip-" + (med.dispense_status || "pending");
            statusChip.innerHTML = '<span class="rx-chip-label">Status:</span>';
            statusChip.appendChild(document.createTextNode(statusLabels[med.dispense_status] || "Pending"));

            var quantityChip = document.createElement("span");
            quantityChip.className = "rx-chip";
            quantityChip.innerHTML = '<span class="rx-chip-label">Quantity:</span>';
            quantityChip.appendChild(document.createTextNode(med.quantity ? med.quantity : "—"));

            var instructionsChip = document.createElement("span");
            instructionsChip.className = "rx-chip";
            instructionsChip.innerHTML = '<span class="rx-chip-label">Instructions:</span>';
            instructionsChip.appendChild(document.createTextNode(med.instructions ? med.instructions : "—"));

            chips.appendChild(statusChip);
            chips.appendChild(quantityChip);
            chips.appendChild(instructionsChip);

            card.appendChild(header);
            card.appendChild(chips);
            medicinesListEl.appendChild(card);
        });

        overlay.classList.add("overlay-visible");
        document.body.style.overflow = "hidden";
    }

    function closeModal() {
        overlay.classList.remove("overlay-visible");
        document.body.style.overflow = "";
    }

    // Event delegation on document, same pattern as the table-actions
    // handlers in doctor-dashboard.js — works even if rows are re-rendered,
    // and avoids relying on querySelectorAll running before every button exists.
    document.addEventListener("click", function (e) {
        var viewBtn = e.target.closest(".btn-view-rx");
        if (viewBtn) {
            var source = viewBtn.getAttribute("data-source");
            var id = parseInt(viewBtn.getAttribute("data-id"), 10);
            openModal(source, id);
            return;
        }

        if (e.target.closest("#rxModalClose")) {
            closeModal();
            return;
        }

        if (e.target === overlay) {
            closeModal();
        }
    });

    document.addEventListener("keydown", function (e) {
        if (e.key === "Escape" && overlay.classList.contains("overlay-visible")) {
            closeModal();
        }
    });
}