// consultation.js
// GabayMed — Consultation page: outcome toggle + dynamic medicine rows.
// The actual save happens server-side in consultation-process.php;
// this file only manages which section of the form is visible and
// builds the medicine rows the backend expects (medicine_id[], quantity[], instructions[]).
//
// Medicines come from window.medicineOptions, an array of
// {id, name, available} objects sourced from inventory_medicines (see
// consultation.php). The <select> value is the catalog medicine_id, not
// free text — that's what lets a prescription be traced back to a real
// catalog row later, for staff to verify and dispense against. An item
// with available === false (current_stock is 0) is still selectable, but
// labeled "Not available in pharmacy" so the doctor sees it up front.
//
// Lab test rows (labTestName[], labTestNotes[]) are handled separately
// from the outcome logic below - ordering a lab test is optional and
// independent of whichever outcome the doctor picks, so that section is
// never hidden/shown by selectOutcome() the way the prescription and
// confine sections are.

document.addEventListener("DOMContentLoaded", function () {
    initConsultationOutcome();
    initLabTests();
});

function initConsultationOutcome() {
    var form = document.getElementById("consultationForm");
    if (!form) return; // Not on the consultation page

    var consultationOnlyBtn = document.getElementById("consultationOnlyBtn");
    var dischargeBtn = document.getElementById("dischargeBtn"); // "Prescription Issued" card, data-action="prescribed"
    var followUpBtn = document.getElementById("followUpBtn");
    var confineBtn = document.getElementById("confineBtn"); // "Admit Patient" card, data-action="admitted"
    var outcomeButtons = [consultationOnlyBtn, dischargeBtn, followUpBtn, confineBtn];

    var prescriptionSection = document.getElementById("prescriptionSection");
    var confineSection = document.getElementById("confineConfirmation");
    var outcomeField = document.getElementById("outcomeField");
    var medicinesList = document.getElementById("medicinesList");
    var addMedicineBtn = document.getElementById("addMedicineBtn");

    var medicineRowCount = 0;

    function setActiveButton(activeBtn) {
        outcomeButtons.forEach(function (btn) {
            if (btn) btn.classList.remove("selected");
        });
        if (activeBtn) activeBtn.classList.add("selected");
    }

    // action is read from each button's data-action attribute, so it always
    // matches what consultation-process.php expects:
    // consultation_only | prescribed | follow_up | admitted
    function selectOutcome(action, btn) {
        outcomeField.value = action;
        setActiveButton(btn);

        if (action === "prescribed") {
            prescriptionSection.hidden = false;
            confineSection.hidden = true;
            if (medicinesList.children.length === 0) {
                addMedicineRow();
            }
            return;
        }

        if (action === "admitted") {
            confineSection.hidden = false;
            prescriptionSection.hidden = true;
            return;
        }

        // "consultation_only" and "follow_up" have no extra section to fill
        // in - save immediately. requestSubmit() (not submit()) is used so
        // the findings textarea's `required` attribute and the submit
        // listener below still run.
        prescriptionSection.hidden = true;
        confineSection.hidden = true;
        form.requestSubmit();
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
            '<input type="text" name="quantity[]" placeholder="e.g. 30 tablets">' +
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

    function escapeHtml(str) {
        var div = document.createElement("div");
        div.textContent = str;
        return div.innerHTML;
    }

    // Wire every outcome button generically off its own data-action, instead
    // of hardcoding action names per button - keeps this file in sync with
    // whatever data-action values consultation.php's cards declare.
    outcomeButtons.forEach(function (btn) {
        if (!btn) return;
        btn.addEventListener("click", function () {
            selectOutcome(btn.getAttribute("data-action"), btn);
        });
    });

    if (addMedicineBtn) {
        addMedicineBtn.addEventListener("click", addMedicineRow);
    }

    // Guard rails so the form can't be submitted without an outcome chosen,
    // or with an empty findings box (native `required` already covers findings,
    // this just gives a clearer message for the outcome choice).
    form.addEventListener("submit", function (e) {
        if (!outcomeField.value) {
            e.preventDefault();
            alert("Please select a consultation outcome before saving.");
        }
    });
}

// Laboratory Tests section - same add/remove-row pattern as the medicine
// rows above, but its own list/field names (labTestName[]/labTestNotes[])
// and reuses the .medicine-row/.medicines-list styling as-is rather than
// introducing new CSS. Always visible, never toggled by outcome selection.
function initLabTests() {
    var labTestsList = document.getElementById("labTestsList");
    var addLabTestBtn = document.getElementById("addLabTestBtn");
    if (!labTestsList || !addLabTestBtn) return; // Not on the consultation page

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

    addLabTestBtn.addEventListener("click", addLabTestRow);
}