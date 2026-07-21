// patient-records.js
// GabayMed — Patient Details modal.
//
// Clicking a patient row or "View" button used to reload the page with
// ?patient_id=... to show the full record. That's replaced here with an
// AJAX fetch (patient-record-ajax.php) + a modal, so the page never
// reloads. This file owns: opening/closing the modal, fetching the data,
// and rendering every section into it.

document.addEventListener("DOMContentLoaded", function () {
    initPatientRecordModal();
});

function initPatientRecordModal() {
    var backdrop = document.getElementById("patientModalBackdrop");
    var modalBody = document.getElementById("modalBody");
    var modalTitle = document.getElementById("modalPatientName");
    var modalAvatar = document.getElementById("modalAvatar");
    var modalPatientId = document.getElementById("modalPatientId");
    var modalStatusBadge = document.getElementById("modalStatusBadge");
    var modalClassificationBadge = document.getElementById("modalClassificationBadge");
    var closeBtn = document.getElementById("modalCloseBtn");

    if (!backdrop) return; // Not on this page

    function openModal() {
        backdrop.classList.add("active");
        document.body.classList.add("modal-open");
    }

    function closeModal() {
        backdrop.classList.remove("active");
        document.body.classList.remove("modal-open");
    }

    function escapeHtml(value) {
        var div = document.createElement("div");
        div.textContent = value === null || value === undefined ? "" : String(value);
        return div.innerHTML;
    }

    // Renders a value, or a "Not on file" placeholder if it's null/empty —
    // used for the fields this schema doesn't currently track
    // (blood type, civil status, allergies, conditions, medications,
    // emergency contact, per-appointment diagnosis).
    function valueOrPlaceholder(value) {
        if (value === null || value === undefined || value === "") {
            return '<span class="not-on-file">Not on file</span>';
        }
        return escapeHtml(value);
    }

    function infoItem(label, value) {
        return (
            '<div class="modal-info-item">' +
            '<span class="info-label">' + escapeHtml(label) + '</span>' +
            '<span class="info-value' + (value ? '' : ' not-on-file') + '">' + valueOrPlaceholder(value) + '</span>' +
            '</div>'
        );
    }

    function getInitials(name) {
        if (!name) return "?";
        var parts = String(name).trim().split(/\s+/).filter(Boolean);
        if (parts.length === 0) return "?";
        var initials = parts.slice(0, 2).map(function (p) {
            return p.charAt(0).toUpperCase();
        }).join("");
        return initials || "?";
    }

    // Maps the patient's account status to the same status-pill classes
    // used elsewhere in the Doctor Portal (search results, directory).
    function patientStatusInfo(rawStatus) {
        var key = String(rawStatus || "").toLowerCase();
        var map = {
            active: ["status-active", "Active"],
            confined: ["status-confined", "Confined"],
            blocked: ["status-discharged", "Blocked"],
        };
        return map[key] || ["status-active", rawStatus || "Unknown"];
    }

    // Maps an appointment status to an existing status-pill variant
    // (the same ones used on the Today's Queue page).
    function appointmentStatusInfo(rawStatus) {
        var key = String(rawStatus || "").toLowerCase().replace(/\s+/g, "_");
        var map = {
            pending: ["status-waiting", "Pending"],
            confirmed: ["status-checked-in", "Confirmed"],
            completed: ["status-completed", "Completed"],
            no_show: ["status-no-show", "No Show"],
            cancelled: ["status-discharged", "Cancelled"],
        };
        return map[key] || ["status-waiting", rawStatus || "Unknown"];
    }

    // Maps a confinement's discharge status to an existing status-pill
    // variant (the same ones used on the Confined Patients page).
    function confinementStatusInfo(rawStatus) {
        var key = String(rawStatus || "").toLowerCase();
        var map = {
            recovered: ["status-stable", "Recovered"],
            transferred: ["status-discharged", "Transferred"],
            dama: ["status-critical", "DAMA"],
            deceased: ["status-critical", "Deceased"],
            ongoing: ["status-confined", "Ongoing"],
        };
        return map[key] || ["status-confined", rawStatus || "Ongoing"];
    }

    function renderTable(columns, rows, emptyMessage) {
        if (!rows || rows.length === 0) {
            return '<div class="empty-state"><p>' + escapeHtml(emptyMessage) + '</p></div>';
        }

        var thead = "<thead><tr>" + columns.map(function (c) {
            return "<th>" + escapeHtml(c.label) + "</th>";
        }).join("") + "</tr></thead>";

        var tbody = "<tbody>" + rows.map(function (row) {
            return "<tr>" + columns.map(function (c) {
                return "<td>" + valueOrPlaceholder(row[c.key]) + "</td>";
            }).join("") + "</tr>";
        }).join("") + "</tbody>";

        return '<div class="table-wrap"><table class="records-table">' + thead + tbody + '</table></div>';
    }

    function renderAppointmentsTable(rows) {
        if (!rows || rows.length === 0) {
            return '<div class="empty-state"><p>No appointment history on file.</p></div>';
        }

        var thead = "<thead><tr><th>Date</th><th>Department</th><th>Doctor</th><th>Status</th><th>Diagnosis</th></tr></thead>";

        var tbody = "<tbody>" + rows.map(function (row) {
            var info = appointmentStatusInfo(row.status);
            return (
                "<tr>" +
                "<td>" + escapeHtml(row.date) + "</td>" +
                "<td>" + escapeHtml(row.department) + "</td>" +
                "<td>" + escapeHtml(row.doctor) + "</td>" +
                '<td><span class="status-pill ' + info[0] + '">' + escapeHtml(info[1]) + "</span></td>" +
                "<td>" + valueOrPlaceholder(row.diagnosis) + "</td>" +
                "</tr>"
            );
        }).join("") + "</tbody>";

        return '<div class="table-wrap"><table class="records-table">' + thead + tbody + '</table></div>';
    }

    // Confinement history reuses the dashboard's existing .activity-timeline
    // component (dot + connecting line) rather than introducing a new one.
    function renderConfinementTimeline(rows) {
        if (!rows || rows.length === 0) {
            return '<div class="empty-state"><p>No confinement history on file.</p></div>';
        }

        var items = rows.map(function (row) {
            var info = confinementStatusInfo(row.discharge_status);
            return (
                '<li class="activity-item">' +
                '<span class="activity-dot"></span>' +
                '<div class="activity-body">' +
                "<p>Room " + valueOrPlaceholder(row.room) + "</p>" +
                '<div class="confinement-meta">' +
                "<span><strong>Confined:</strong> " + escapeHtml(row.date_confined) + "</span>" +
                "<span><strong>Discharged:</strong> " + (row.date_discharged ? escapeHtml(row.date_discharged) : "—") + "</span>" +
                '<span class="status-pill ' + info[0] + '">' + escapeHtml(info[1]) + "</span>" +
                "</div></div></li>"
            );
        }).join("");

        return '<ul class="activity-timeline">' + items + '</ul>';
    }

    function cardCount(n, noun) {
        return n + " " + noun + (n === 1 ? "" : "s");
    }

    function renderPatient(data) {
        var b = data.basic;
        var m = data.medical;

        modalTitle.textContent = b.full_name;
        modalAvatar.textContent = getInitials(b.full_name);
        modalPatientId.textContent = "Patient ID: " + b.patient_id;

        var statusInfo = patientStatusInfo(b.status);
        modalStatusBadge.className = "status-pill " + statusInfo[0];
        modalStatusBadge.textContent = statusInfo[1];

        if (b.classification) {
            modalClassificationBadge.className = "classification-badge " + b.classification.class;
            modalClassificationBadge.textContent = b.classification.label;
        }

        var html = "";

        // Card 1 — Personal Information
        html += '<div class="card modal-card">';
        html += '<div class="card-header"><h2>Personal Information</h2></div>';
        html += '<div class="modal-info-grid">';
        html += infoItem("Age", b.age !== null && b.age !== undefined ? b.age + " yrs" : null);
        html += infoItem("Gender", b.gender);
        html += infoItem("Blood Type", b.blood_type);
        html += infoItem("Contact Number", b.contact_number);
        html += infoItem("Address", b.address);
        html += infoItem("Birthday", b.birthdate);
        html += infoItem("Civil Status", b.civil_status);
        html += infoItem("Emergency Contact", m.emergency_contact);
        html += "</div></div>";

        // Card 2 — Medical Information
        html += '<div class="card modal-card">';
        html += '<div class="card-header"><h2>Medical Information</h2></div>';
        html += '<div class="modal-info-grid">';
        html += infoItem("Allergies", m.allergies);
        html += infoItem("Medical Conditions", m.conditions);
        html += infoItem("Current Medications", m.medications);
        html += "</div></div>";

        // Card 3 — Appointment History
        html += '<div class="card modal-card modal-card-full">';
        html += '<div class="card-header"><h2>Appointment History</h2><span class="card-subtitle">' +
            cardCount(data.appointments.length, "record") + "</span></div>";
        html += renderAppointmentsTable(data.appointments);
        html += "</div>";

        // Card 4 — Confinement History
        html += '<div class="card modal-card modal-card-full">';
        html += '<div class="card-header"><h2>Confinement History</h2><span class="card-subtitle">' +
            cardCount(data.confinements.length, "record") + "</span></div>";
        html += renderConfinementTimeline(data.confinements);
        html += "</div>";

        // Card 5 — Prescription History
        html += '<div class="card modal-card modal-card-full">';
        html += '<div class="card-header"><h2>Prescription History</h2><span class="card-subtitle">' +
            cardCount(data.prescriptions.length, "record") + "</span></div>";
        html += renderTable(
            [
                { key: "medicine", label: "Medicine" },
                { key: "dosage", label: "Dosage" },
                { key: "instructions", label: "Instructions" },
                { key: "date_issued", label: "Date Issued" },
            ],
            data.prescriptions,
            "No prescription history on file."
        );
        html += "</div>";

        modalBody.innerHTML = '<div class="modal-cards-grid">' + html + '</div>';
    }

    function resetHeader() {
        modalTitle.textContent = "Patient Record";
        modalAvatar.textContent = "";
        modalPatientId.textContent = "";
        modalStatusBadge.className = "status-pill";
        modalStatusBadge.textContent = "";
        modalClassificationBadge.className = "classification-badge";
        modalClassificationBadge.textContent = "";
    }

    function loadPatient(patientId) {
        resetHeader();
        modalBody.innerHTML = '<div class="modal-loading">Loading patient record...</div>';
        openModal();

        fetch("patient-record-ajax.php?patient_id=" + encodeURIComponent(patientId))
            .then(function (response) {
                if (!response.ok) {
                    throw new Error("Request failed");
                }
                return response.json();
            })
            .then(function (data) {
                if (data.error) {
                    modalBody.innerHTML = '<div class="modal-error">' + escapeHtml(data.error) + "</div>";
                    return;
                }
                renderPatient(data);
            })
            .catch(function () {
                modalBody.innerHTML = '<div class="modal-error">Something went wrong loading this patient\'s record. Please try again.</div>';
            });
    }

    // Event delegation: works for rows/buttons rendered at page load
    // (search results, directory) without needing to bind each one.
    document.addEventListener("click", function (e) {
        var trigger = e.target.closest(".view-patient-btn, .patient-row");
        if (!trigger) return;

        // Don't double-fire when the "View" button inside a row is
        // clicked — the row's own click handler would otherwise run too.
        if (trigger.classList.contains("view-patient-btn")) {
            e.stopPropagation();
        }

        var patientId = trigger.getAttribute("data-patient-id");
        if (patientId) {
            loadPatient(patientId);
        }
    });

    // Close: X button
    closeBtn.addEventListener("click", closeModal);

    // Close: clicking outside the modal box (on the backdrop itself)
    backdrop.addEventListener("click", function (e) {
        if (e.target === backdrop) {
            closeModal();
        }
    });

    // Close: Escape key, only while the modal is actually open
    document.addEventListener("keydown", function (e) {
        if (e.key === "Escape" && backdrop.classList.contains("active")) {
            closeModal();
        }
    });
}