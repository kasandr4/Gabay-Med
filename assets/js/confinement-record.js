// confinement-record.js
// GabayMed — Confinement Record page interactions.
//
// This file was referenced by confinement-record.php but did not exist,
// so "Discharge Patient" never actually revealed the outcome form. It now:
//   1. Shows the discharge outcome form when "Discharge Patient" is clicked.
//   2. Adds an "Are you sure?" confirmation modal before that form actually
//      submits, since discharging a patient is irreversible from this page.

document.addEventListener("DOMContentLoaded", function () {
    initDischargeToggle();
    initDischargeConfirmModal();
});

/**
 * Reveals the (initially hidden) discharge outcome form when the
 * "Discharge Patient" button is clicked.
 */
function initDischargeToggle() {
    var showBtn = document.getElementById("showDischargeFormBtn");
    var form = document.getElementById("dischargeForm");

    if (!showBtn || !form) return; // Not on an ongoing confinement's page

    showBtn.addEventListener("click", function () {
        form.hidden = false;
        showBtn.hidden = true;
    });
}

/**
 * Intercepts the discharge form's submit and shows a confirmation modal
 * first. Only submits for real once the doctor confirms in the modal.
 */
function initDischargeConfirmModal() {
    var form = document.getElementById("dischargeForm");
    var modal = document.getElementById("dischargeConfirmModal");
    var cancelBtn = document.getElementById("dischargeConfirmCancel");
    var proceedBtn = document.getElementById("dischargeConfirmProceed");

    if (!form || !modal || !cancelBtn || !proceedBtn) return;

    function openModal() {
        modal.classList.add("active");
        document.body.classList.add("modal-open");
    }

    function closeModal() {
        modal.classList.remove("active");
        document.body.classList.remove("modal-open");
    }

    form.addEventListener("submit", function (e) {
        // Once confirmed via the modal, let the real submit go through.
        if (form.dataset.confirmed === "true") return;

        // Let the browser's own "required" validation handle an empty
        // outcome selection instead of popping the modal over it.
        var selectedOutcome = form.querySelector('input[name="discharge_status"]:checked');
        if (!selectedOutcome) return;

        e.preventDefault();
        openModal();
    });

    cancelBtn.addEventListener("click", closeModal);

    // Clicking the dimmed backdrop (outside the box) also cancels.
    modal.addEventListener("click", function (e) {
        if (e.target === modal) closeModal();
    });

    proceedBtn.addEventListener("click", function () {
        form.dataset.confirmed = "true";
        closeModal();
        form.submit();
    });
}