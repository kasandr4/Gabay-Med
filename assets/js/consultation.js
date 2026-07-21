// consultation.js
// GabayMed — Consultation page: outcome toggle + dynamic medicine rows.
// The actual save happens server-side in consultation-process.php;
// this file only manages which section of the form is visible and
// builds the medicine rows the backend expects (medicine_name[], quantity[], instructions[]).
//
// Each medicine row also shows a live stock hint (In stock / Low stock /
// Out of stock / Exceeds available stock) read from data-stock on the
// selected <option>, which comes from a real inventory_medicines query
// in consultation.php. This is advisory only — it does not block
// "Issue Prescription", and stock is not actually deducted on submit.
// See the TODO(backend) note next to medicineOptions in consultation.php.

document.addEventListener("DOMContentLoaded", function () {
    initConsultationOutcome();
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
            optionsHtml += '<option value="' + escapeHtml(med.name) + '" data-stock="' + med.stock + '" data-unit="' + escapeHtml(med.unit) + '">' + escapeHtml(med.name) + "</option>";
        });

        row.innerHTML =
            '<div>' +
            '<label>Medicine</label>' +
            '<select name="medicine_name[]" required>' + optionsHtml + '</select>' +
            '<p class="medicine-stock-hint"></p>' +
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

        var select = row.querySelector('select[name="medicine_name[]"]');
        var quantityInput = row.querySelector('input[name="quantity[]"]');
        var stockHint = row.querySelector(".medicine-stock-hint");

        function refreshStockHint() {
            var opt = select.options[select.selectedIndex];
            if (!opt || !opt.value) {
                stockHint.textContent = "";
                stockHint.className = "medicine-stock-hint";
                return;
            }

            var stock = parseInt(opt.getAttribute("data-stock"), 10) || 0;
            var unit = opt.getAttribute("data-unit") || "units";
            var requested = parseInt(quantityInput.value, 10); // best-effort: quantity is free text like "30 tablets"

            var label = "In stock: " + stock + " " + unit;
            var level = "ok";
            if (stock <= 0) {
                label = "Out of stock (0 " + unit + " on hand)";
                level = "danger";
            } else if (!isNaN(requested) && requested > stock) {
                label += " \u2014 exceeds available stock";
                level = "danger";
            } else if (stock <= 10) {
                label += " \u2014 low stock";
                level = "warning";
            }

            stockHint.textContent = label;
            stockHint.className = "medicine-stock-hint medicine-stock-hint-" + level;
        }

        select.addEventListener("change", refreshStockHint);
        quantityInput.addEventListener("input", refreshStockHint);

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