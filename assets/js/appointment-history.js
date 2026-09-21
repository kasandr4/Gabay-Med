// appointment-history.js
// GabayMed — Doctor Portal: Appointment History visit-timeline modal.
//
// Same modal shell/interaction pattern as patient-records.js (open/close,
// fetch-on-click, event delegation on .patient-row/.view-patient-btn), but
// renders a different shape of data: a chronological list of this
// doctor's own visits with this patient, each with nested
// prescriptions/lab orders, plus any confinements this doctor attended.
// Kept as its own file rather than reusing patient-records.js since the
// render logic doesn't overlap enough to share.

document.addEventListener("DOMContentLoaded", function () {
    initAppointmentHistoryModal();
});

function initAppointmentHistoryModal() {
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

    function valueOrPlaceholder(value) {
        if (value === null || value === undefined || value === "") {
            return '<span class="not-on-file">Not on file</span>';
        }
        return escapeHtml(value);
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

    // Same status-pill classes used elsewhere in the Doctor Portal.
    function patientStatusInfo(rawStatus) {
        var key = String(rawStatus || "").toLowerCase();
        var map = {
            active: ["status-active", "Active"],
            confined: ["status-confined", "Confined"],
            blocked: ["status-discharged", "Blocked"],
        };
        return map[key] || ["status-active", rawStatus || "Unknown"];
    }

    function outcomeStatusInfo(rawOutcome) {
        var key = String(rawOutcome || "").toLowerCase();
        var map = {
            "consultation only": ["status-checked-in", "Consultation Only"],
            "prescribed": ["status-completed", "Prescribed"],
            "follow up": ["status-waiting", "Follow-up"],
            "admitted": ["status-confined", "Admitted"],
        };
        return map[key] || ["status-checked-in", rawOutcome || "Unknown"];
    }

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
            return '<p class="not-on-file" style="margin: 4px 0 0;">' + escapeHtml(emptyMessage) + '</p>';
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

    function renderPrescriptionsTable(rows) {
        return renderTable(
            [
                { key: "medicine", label: "Medicine" },
                { key: "dosage", label: "Dosage" },
                { key: "instructions", label: "Instructions" },
                { key: "status", label: "Status" },
            ],
            rows,
            "No prescriptions issued on this visit."
        );
    }

    function renderLabOrdersTable(rows) {
        return renderTable(
            [
                { key: "test_name", label: "Test" },
                { key: "notes", label: "Notes" },
                { key: "date", label: "Date" },
            ],
            rows,
            "No laboratory orders on this visit."
        );
    }

    // One visit = one card: date/outcome header, diagnosis + clinical
    // notes, then nested prescriptions and lab orders tables.
    function renderVisitCard(visit) {
        var info = outcomeStatusInfo(visit.outcome);
        var html = '<div class="card modal-card modal-card-full">';
        html += '<div class="card-header"><h2>' + escapeHtml(visit.date) + '</h2>' +
            '<span class="status-pill ' + info[0] + '">' + escapeHtml(info[1]) + '</span></div>';

        html += '<div class="modal-info-grid">';
        html += '<div class="modal-info-item"><span class="info-label">Diagnosis</span>' +
            '<span class="info-value' + (visit.diagnosis ? '' : ' not-on-file') + '">' + valueOrPlaceholder(visit.diagnosis) + '</span></div>';
        html += '<div class="modal-info-item"><span class="info-label">Clinical Notes</span>' +
            '<span class="info-value' + (visit.clinical_notes ? '' : ' not-on-file') + '">' + valueOrPlaceholder(visit.clinical_notes) + '</span></div>';
        html += '</div>';

        if (visit.prescriptions && visit.prescriptions.length > 0) {
            html += '<p class="modal-subsection-title">Prescriptions</p>';
            html += renderPrescriptionsTable(visit.prescriptions);
        }

        if (visit.lab_orders && visit.lab_orders.length > 0) {
            html += '<p class="modal-subsection-title">Laboratory Orders</p>';
            html += renderLabOrdersTable(visit.lab_orders);
        }

        html += '</div>';
        return html;
    }

    // One confinement = one card: admission/discharge/room/status, its own
    // lab orders, and its confinement_notes as an activity timeline (same
    // component the Hospital Census drawer uses).
    function renderConfinementCard(conf) {
        var info = confinementStatusInfo(conf.discharge_status);
        var html = '<div class="card modal-card modal-card-full">';
        html += '<div class="card-header"><h2>Room ' + escapeHtml(conf.room) + '</h2>' +
            '<span class="status-pill ' + info[0] + '">' + escapeHtml(info[1]) + '</span></div>';

        html += '<div class="modal-info-grid">';
        html += '<div class="modal-info-item"><span class="info-label">Confined</span>' +
            '<span class="info-value">' + escapeHtml(conf.date_confined) + '</span></div>';
        html += '<div class="modal-info-item"><span class="info-label">Discharged</span>' +
            '<span class="info-value' + (conf.date_discharged ? '' : ' not-on-file') + '">' + valueOrPlaceholder(conf.date_discharged) + '</span></div>';
        html += '<div class="modal-info-item"><span class="info-label">Clinical Status</span>' +
            '<span class="info-value' + (conf.clinical_status ? '' : ' not-on-file') + '">' + valueOrPlaceholder(conf.clinical_status) + '</span></div>';
        html += '<div class="modal-info-item"><span class="info-label">Diagnosis</span>' +
            '<span class="info-value' + (conf.final_diagnosis ? '' : ' not-on-file') + '">' + valueOrPlaceholder(conf.final_diagnosis) + '</span></div>';
        html += '<div class="modal-info-item"><span class="info-label">Follow-up Date</span>' +
            '<span class="info-value' + (conf.follow_up_date ? '' : ' not-on-file') + '">' + valueOrPlaceholder(conf.follow_up_date) + '</span></div>';
        html += '</div>';

        if (conf.follow_up_instructions) {
            html += '<p class="modal-subsection-title">Follow-up Instructions</p>';
            html += '<p class="info-value" style="margin: 0;">' + escapeHtml(conf.follow_up_instructions) + '</p>';
        }

        if ((conf.discharge_medications && conf.discharge_medications.length > 0) || conf.discharge_medications_notes) {
            html += '<p class="modal-subsection-title">Discharge Medications</p>';
            if (conf.discharge_medications && conf.discharge_medications.length > 0) {
                html += renderTable(
                    [
                        { key: "medicine", label: "Medicine" },
                        { key: "dosage", label: "Dosage" },
                        { key: "instructions", label: "Instructions" },
                        { key: "status", label: "Status" },
                    ],
                    conf.discharge_medications,
                    ""
                );
            }
            if (conf.discharge_medications_notes) {
                html += '<p class="info-value" style="margin: 8px 0 0;">' + escapeHtml(conf.discharge_medications_notes) + '</p>';
            }
        }

        if (conf.lab_orders && conf.lab_orders.length > 0) {
            html += '<p class="modal-subsection-title">Laboratory Orders</p>';
            html += renderLabOrdersTable(conf.lab_orders);
        }

        if (conf.notes && conf.notes.length > 0) {
            html += '<p class="modal-subsection-title">Notes</p>';
            html += '<ul class="activity-timeline">' + conf.notes.map(function (item) {
                return '<li class="activity-item"><span class="activity-dot"></span>' +
                    '<div class="activity-body"><p>' + escapeHtml(item.text) + '</p>' +
                    '<span class="activity-time">' + escapeHtml(item.time) + '</span></div></li>';
            }).join("") + '</ul>';
        }

        html += '</div>';
        return html;
    }

    function cardCount(n, noun) {
        return n + " " + noun + (n === 1 ? "" : "s");
    }

    function renderPatient(data) {
        var b = data.basic;

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

        // Visit timeline
        html += '<div class="card modal-card modal-card-full">';
        html += '<div class="card-header"><h2>Visit History</h2><span class="card-subtitle">' +
            cardCount(data.visits.length, "visit") + "</span></div>";
        html += "</div>";
        if (data.visits.length === 0) {
            html += '<div class="card modal-card modal-card-full"><p class="not-on-file">No consultation visits on file for this patient.</p></div>';
        } else {
            html += data.visits.map(renderVisitCard).join("");
        }

        // Confinements (only if this doctor attended any for this patient)
        if (data.confinements.length > 0) {
            html += '<div class="card modal-card modal-card-full">';
            html += '<div class="card-header"><h2>Confinement</h2><span class="card-subtitle">' +
                cardCount(data.confinements.length, "record") + "</span></div>";
            html += "</div>";
            html += data.confinements.map(renderConfinementCard).join("");
        }

        modalBody.innerHTML = '<div class="modal-cards-grid">' + html + '</div>';
    }

    function resetHeader() {
        modalTitle.textContent = "Patient History";
        modalAvatar.textContent = "";
        modalPatientId.textContent = "";
        modalStatusBadge.className = "status-pill";
        modalStatusBadge.textContent = "";
        modalClassificationBadge.className = "classification-badge";
        modalClassificationBadge.textContent = "";
    }

    function loadPatient(patientId) {
        resetHeader();
        modalBody.innerHTML = '<div class="modal-loading">Loading visit history...</div>';
        openModal();

        fetch("appointment-history-ajax.php?patient_id=" + encodeURIComponent(patientId))
            .then(function (response) {
                if (!response.ok) {
                    return response.json().then(function (data) {
                        throw new Error(data.error || "Request failed");
                    });
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
            .catch(function (err) {
                modalBody.innerHTML = '<div class="modal-error">' + escapeHtml(err && err.message ? err.message : "Something went wrong loading this patient's history. Please try again.") + "</div>";
            });
    }

    // Event delegation: works for rows/buttons rendered at page load
    // (search results, directory) without needing to bind each one.
    document.addEventListener("click", function (e) {
        var trigger = e.target.closest(".view-patient-btn, .patient-row");
        if (!trigger) return;

        if (trigger.classList.contains("view-patient-btn")) {
            e.stopPropagation();
        }

        var patientId = trigger.getAttribute("data-patient-id");
        if (patientId) {
            loadPatient(patientId);
        }
    });

    closeBtn.addEventListener("click", closeModal);

    backdrop.addEventListener("click", function (e) {
        if (e.target === backdrop) {
            closeModal();
        }
    });

    document.addEventListener("keydown", function (e) {
        if (e.key === "Escape" && backdrop.classList.contains("active")) {
            closeModal();
        }
    });
}