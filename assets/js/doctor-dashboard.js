// doctor-dashboard.js
// GabayMed — Doctor Dashboard interactions

document.addEventListener("DOMContentLoaded", function () {
    initSidebarToggle();
    initQuickActions();
    initLogout();
    initQueueFilters();
    initFollowUpFilters();
});

/**
 * Mobile off-canvas sidebar: hamburger button opens/closes the sidebar,
 * the overlay closes it on tap. Mirrors the Patient Portal's toggle pattern.
 * CSS already handles the animation via .sidebar.active / body.sidebar-open;
 * this just flips those classes.
 */
function initSidebarToggle() {
    var toggleBtn = document.getElementById("sidebarToggle");
    var sidebar = document.getElementById("sidebar");
    var overlay = document.getElementById("sidebarOverlay");

    if (!toggleBtn || !sidebar || !overlay) return;

    function openSidebar() {
        sidebar.classList.add("active");
        overlay.classList.add("active");
        document.body.classList.add("sidebar-open");
        toggleBtn.setAttribute("aria-expanded", "true");
    }

    function closeSidebar() {
        sidebar.classList.remove("active");
        overlay.classList.remove("active");
        document.body.classList.remove("sidebar-open");
        toggleBtn.setAttribute("aria-expanded", "false");
    }

    toggleBtn.addEventListener("click", function () {
        if (sidebar.classList.contains("active")) {
            closeSidebar();
        } else {
            openSidebar();
        }
    });

    overlay.addEventListener("click", closeSidebar);
}

/**
 * Today's Queue page: live search + department/status filters.
 * Pure client-side filtering over the rows already rendered by PHP.
 * Once paginated/server-side data is introduced, this can be swapped
 * for an AJAX call that re-renders the table body.
 */
function initQueueFilters() {
    var searchInput = document.getElementById("queueSearchInput");
    var departmentFilter = document.getElementById("departmentFilter");
    var statusFilter = document.getElementById("statusFilter");
    var table = document.getElementById("queueTable");
    var noResults = document.getElementById("noResultsRow");

    if (!table) return; // Not on the Today's Queue page

    var rows = table.querySelectorAll(".queue-row");

    function applyFilters() {
        var searchTerm = searchInput ? searchInput.value.trim().toLowerCase() : "";
        var department = departmentFilter ? departmentFilter.value : "all";
        var status = statusFilter ? statusFilter.value : "all";
        var visibleCount = 0;

        rows.forEach(function (row) {
            var name = row.getAttribute("data-name") || "";
            var rowDept = row.getAttribute("data-department") || "";
            var rowStatus = row.getAttribute("data-status") || "";

            var matchesSearch = name.indexOf(searchTerm) !== -1;
            var matchesDept = department === "all" || rowDept === department;
            var matchesStatus = status === "all" || rowStatus === status;

            var isVisible = matchesSearch && matchesDept && matchesStatus;
            row.hidden = !isVisible;

            if (isVisible) visibleCount++;
        });

        if (noResults) {
            noResults.hidden = visibleCount !== 0;
        }
    }

    if (searchInput) searchInput.addEventListener("input", applyFilters);
    if (departmentFilter) departmentFilter.addEventListener("change", applyFilters);
    if (statusFilter) statusFilter.addEventListener("change", applyFilters);
}


/**
 * Quick action cards (Start Consultation, Confined Patients,
 * Patient Lookup, My Reports).
 */
function initQuickActions() {
    var actions = document.querySelectorAll(".quick-action");

    actions.forEach(function (action) {
        action.addEventListener("click", function () {
            var href = action.getAttribute("data-href");
            if (href) {
                window.location.href = href;
            }
        });
    });
}

/**
 * Logout button placeholder.
 */
function initLogout() {
    var logoutBtn = document.querySelector(".logout-btn");
    if (!logoutBtn) return;

    logoutBtn.addEventListener("click", function () {
        // TODO: replace with real logout endpoint (e.g. ../includes/logout.php)
        console.log("Logout requested");
    });
}

/**
 * Follow-Up Scheduling page: live search + status filter.
 * Same client-side filtering approach as initQueueFilters().
 */
function initFollowUpFilters() {
    var searchInput = document.getElementById("followUpSearchInput");
    var statusFilter = document.getElementById("followUpStatusFilter");
    var table = document.getElementById("followUpTable");
    var noResults = document.getElementById("noFollowUpResults");

    if (!table) return; // Not on the Follow-Up Scheduling page

    function applyFilters() {
        var searchTerm = searchInput ? searchInput.value.trim().toLowerCase() : "";
        var status = statusFilter ? statusFilter.value : "all";
        var visibleCount = 0;
        var rows = table.querySelectorAll(".followup-row");

        rows.forEach(function (row) {
            var name = row.getAttribute("data-name") || "";
            var rowStatus = row.getAttribute("data-status") || "";

            var matchesSearch = name.indexOf(searchTerm) !== -1;
            var matchesStatus = status === "all" || rowStatus === status;

            var isVisible = matchesSearch && matchesStatus;
            row.hidden = !isVisible;

            if (isVisible) visibleCount++;
        });

        if (noResults) {
            noResults.hidden = visibleCount !== 0;
        }
    }

    if (searchInput) searchInput.addEventListener("input", applyFilters);
    if (statusFilter) statusFilter.addEventListener("change", applyFilters);
}

// NOTE: initFollowUpActions() and initScheduleFollowUpForm() were removed.
// They were UI-only simulations written before the Follow-Up backend
// existed (Mark Completed/Cancel/Schedule all just faked a DOM update and
// called e.preventDefault(), so nothing was ever actually saved). Now that
// follow-up-process.php and follow-up-update.php exist, the schedule form
// and the two action buttons on follow-up.php are real <form> submissions
// and need to hit the server - the client-side simulation was silently
// blocking that. Live search/filter (initFollowUpFilters, above) is
// unaffected and still works the same way.