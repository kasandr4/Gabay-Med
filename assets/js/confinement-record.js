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
    initLabTestRows();
    initDischargeMedicineRows();
});

/**
 * Order Lab Test section - same add/remove-row pattern as
 * consultation.js's initLabTests(), same labTestName[]/labTestNotes[]
 * field names and .medicine-row/.medicines-list styling, just reading
 * window.labTestOptions from confinement-record.php's own inline script
 * instead of consultation.php's.
 */
function initLabTestRows() {
    var labTestsList = document.getElementById("labTestsList");
    var addLabTestBtn = document.getElementById("addLabTestBtn");
    if (!labTestsList || !addLabTestBtn) return; // Not on an ongoing confinement's page

    var labTestRowCount = 0;

    function escapeHtml(str) {
        var div = document.createElement("div");
        div.textContent = str;
        return div.innerHTML;
    }

    function addLabTestRow() {
        labTestRowCount++;
        var row = document.createElement("div");
        row.className = "medicine-row medicine-row-3col";
        row.setAttribute("data-row-id", labTestRowCount);

        var optionsHtml = '<option value="">Select test</option>';
        (window.labTestOptions || []).forEach(function (name) {
            optionsHtml += '<option value="' + escapeHtml(name) + '">' + escapeHtml(name) + "</option>";
        });

        row.innerHTML =
            '<div>' +
            '<label>Test</label>' +
            '<select name="labTestName[]" required>' + optionsHtml + '</select>' +
            '</div>' +
            '<div>' +
            '<label>Notes</label>' +
            '<input type="text" name="labTestNotes[]" placeholder="e.g. Fasting required">' +
            '</div>' +
            '<button type="button" class="medicine-remove-btn" aria-label="Remove lab test">' +
            '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
            '<line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line>' +
            '</svg>' +
            '</button>';

        row.querySelector(".medicine-remove-btn").addEventListener("click", function () {
            row.remove();
        });

        labTestsList.appendChild(row);
    }

    // Start with one row already visible - unlike consultation.php, this
    // form has nothing else on the page to prompt a doctor to click "Add
    // Lab Test" first, so an empty list here would look broken.
    addLabTestRow();
    addLabTestBtn.addEventListener("click", addLabTestRow);
}

/**
 * "Medications to Continue" section on the discharge form - same
 * medicine_id[]/quantity[]/instructions[] shape and same {id, name,
 * available} window.medicineOptions as consultation.js's addMedicineRow(),
 * reading confinement-record.php's own copy of that catalog instead of
 * consultation.php's. Starts empty (unlike lab tests above) since
 * discharge medications are optional - a doctor adds rows only if the
 * patient is actually going home with medicine.
 */
function initDischargeMedicineRows() {
    var medicinesList = document.getElementById("dischargeMedicinesList");
    var addMedicineBtn = document.getElementById("addDischargeMedicineBtn");
    if (!medicinesList || !addMedicineBtn) return; // Not on an ongoing confinement's page

    var medicineRowCount = 0;

    function escapeHtml(str) {
        var div = document.createElement("div");
        div.textContent = str;
        return div.innerHTML;
    }

    function addMedicineRow() {
        medicineRowCount++;
        var row = document.createElement("div");
        row.className = "medicine-row";
        row.setAttribute("data-row-id", medicineRowCount);

        var optionsHtml = '<option value="">Select medicine</option>';
        (window.medicineOptions || []).forEach(function (med) {
            var label = escapeHtml(med.name);
            var style = "";
            if (!med.available) {
                label += " \u2014 Not available in pharmacy";
                style = ' style="color:#b91c1c;"';
            }
            optionsHtml += '<option value="' + med.id + '"' + style + ">" + label + "</option>";
        });

        row.innerHTML =
            '<div>' +
            '<label>Medicine</label>' +
            '<select name="medicine_id[]" required>' + optionsHtml + '</select>' +
            '</div>' +
            '<div>' +
            '<label>Quantity</label>' +
            '<input type="text" name="quantity[]" placeholder="e.g. 20">' +
            '</div>' +
            '<div>' +
            '<label>Instructions</label>' +
            '<input type="text" name="instructions[]" placeholder="e.g. Twice daily after meals">' +
            '</div>' +
            '<button type="button" class="medicine-remove-btn" aria-label="Remove medicine">' +
            '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
            '<line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line>' +
            '</svg>' +
            '</button>';

        row.querySelector(".medicine-remove-btn").addEventListener("click", function () {
            row.remove();
        });

        medicinesList.appendChild(row);
    }

    addMedicineBtn.addEventListener("click", addMedicineRow);
}

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