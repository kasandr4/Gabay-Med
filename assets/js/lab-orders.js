// lab-orders.js
// GabayMed — Patient Lab Orders page
// Populates and controls the "View Details" modal using labOrdersData,
// which is rendered server-side in lab-orders.php and already scoped
// to the logged-in patient only. No AJAX calls needed.
//
// Same structure as prescriptions.js - kept as a separate file (rather
// than merged into it) because the two pages' element IDs overlap
// (#rxModalOverlay etc, reused deliberately from prescriptions.css) and
// are never on the page at the same time, so there's no ID collision at
// runtime, but keeping the JS separate keeps each page's script matched
// to its own data shape (tests vs medicines).
//
// FIXED 2026-09-20: status label text (Pending/In Progress/Results Ready)
// now reads from window.labStatusLabels, embedded by lab-orders.php from
// includes/status_labels.php's get_lab_status_labels() - this used to be
// a hardcoded copy here that had already drifted from staff/lab-queue.
// php's own hardcoded copy (that one said "Accepted" instead of "In
// Progress" for the same status). The inline fallback is only for the
// case lab-orders.php somehow doesn't embed the global, not a second
// source of truth to keep in sync by hand.

document.addEventListener("DOMContentLoaded", function () {
    initLabOrderModal();
});

function initLabOrderModal() {
    var overlay = document.getElementById("rxModalOverlay");
    var closeBtn = document.getElementById("rxModalClose");
    var doctorEl = document.getElementById("rxDetailDoctor");
    var dateEl = document.getElementById("rxDetailDate");
    var dateLabelEl = document.getElementById("rxDetailDateLabel");
    var departmentEl = document.getElementById("rxDetailDepartment");
    var testsListEl = document.getElementById("rxMedicinesList");

    // Fail loudly instead of silently: if any expected element or the
    // data payload is missing, log exactly what's missing so it shows
    // up in the browser console instead of just "nothing happens".
    var missing = [];
    if (!overlay) missing.push("#rxModalOverlay");
    if (!closeBtn) missing.push("#rxModalClose");
    if (!doctorEl) missing.push("#rxDetailDoctor");
    if (!dateEl) missing.push("#rxDetailDate");
    if (!departmentEl) missing.push("#rxDetailDepartment");
    if (!testsListEl) missing.push("#rxMedicinesList");
    if (typeof labOrdersData === "undefined") missing.push("labOrdersData");

    if (missing.length > 0) {
        console.error("Lab order modal: missing " + missing.join(", ") + ". Check that lab-orders.php markup matches lab-orders.js.");
        return;
    }

    // group_key is "consultation-{id}" or "confinement-{id}" - a lab
    // order can now come from either source (see
    // 022_confinement_orders_and_discharge.sql), so matching switched
    // from a bare consultation_id to this generic string key.
    function findGroup(groupKey) {
        for (var i = 0; i < labOrdersData.length; i++) {
            if (labOrdersData[i].group_key === groupKey) {
                return labOrdersData[i];
            }
        }
        return null;
    }

    function openModal(groupKey) {
        var record = findGroup(groupKey);
        if (!record) {
            console.error("Lab order modal: no record found for group_key " + groupKey);
            return;
        }

        doctorEl.textContent = record.doctor_name || "—";
        dateEl.textContent = record.date || "—";
        if (dateLabelEl) dateLabelEl.textContent = record.date_label || "Date";
        departmentEl.textContent = record.department || "—";

        testsListEl.innerHTML = "";
        record.tests.forEach(function (test) {
            var card = document.createElement("div");
            card.className = "rx-medicine-card";

            var header = document.createElement("div");
            header.className = "rx-medicine-card-header";

            var icon = document.createElement("span");
            icon.className = "rx-medicine-icon";
            icon.innerHTML = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 2v6.5L4 20a1 1 0 0 0 1 2h14a1 1 0 0 0 1-2l-5-11.5V2"></path><path d="M8.5 2h7"></path><path d="M6 15h12"></path></svg>';

            var name = document.createElement("span");
            name.className = "rx-medicine-name";
            name.textContent = test.name;

            header.appendChild(icon);
            header.appendChild(name);

            var chips = document.createElement("div");
            chips.className = "rx-medicine-chips";

            // Status chip (2026-09-17) - same rx-chip-{status} pattern
            // prescriptions.js already uses for dispense status; see
            // assets/css/prescriptions.css's .rx-chip-pending/-accepted/
            // -completed.
            var statusLabels = window.labStatusLabels || { pending: "Pending", accepted: "In Progress", completed: "Results Ready" };
            var statusChip = document.createElement("span");
            statusChip.className = "rx-chip rx-chip-" + (test.status || "pending");
            statusChip.innerHTML = '<span class="rx-chip-label">Status:</span>';
            statusChip.appendChild(document.createTextNode(statusLabels[test.status] || "Pending"));
            chips.appendChild(statusChip);

            var notesChip = document.createElement("span");
            notesChip.className = "rx-chip";
            notesChip.innerHTML = '<span class="rx-chip-label">Notes:</span>';
            notesChip.appendChild(document.createTextNode(test.notes ? test.notes : "—"));

            chips.appendChild(notesChip);

            card.appendChild(header);
            card.appendChild(chips);
            testsListEl.appendChild(card);
        });

        overlay.classList.add("overlay-visible");
        document.body.style.overflow = "hidden";
    }

    function closeModal() {
        overlay.classList.remove("overlay-visible");
        document.body.style.overflow = "";
    }

    // Event delegation on document, same pattern as prescriptions.js —
    // works even if rows are re-rendered.
    document.addEventListener("click", function (e) {
        var viewBtn = e.target.closest(".btn-view-rx");
        if (viewBtn) {
            var groupKey = viewBtn.getAttribute("data-group-key");
            openModal(groupKey);
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