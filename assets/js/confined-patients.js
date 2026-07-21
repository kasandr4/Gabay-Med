// confined-patients.js
// GabayMed — Confined Patients page interactions (UI only, no backend calls yet)

document.addEventListener("DOMContentLoaded", function () {
    initConfinedFilters();
    initRefreshButton();
});

/**
 * Confined Patients page: live search + location/status filters.
 * Pure client-side filtering over the rows already rendered by PHP.
 */
function initConfinedFilters() {
    var searchInput = document.getElementById("confinedSearchInput");
    var locationFilter = document.getElementById("locationFilter");
    var statusFilter = document.getElementById("statusFilter");
    var table = document.getElementById("confinedTable");
    var noResults = document.getElementById("noResultsRow");

    if (!table) return; // Not on the Confined Patients page

    var rows = table.querySelectorAll(".confined-row");

    function applyFilters() {
        var searchTerm = searchInput ? searchInput.value.trim().toLowerCase() : "";
        var location = locationFilter ? locationFilter.value : "all";
        var status = statusFilter ? statusFilter.value : "all";
        var visibleCount = 0;

        rows.forEach(function (row) {
            var name = row.getAttribute("data-name") || "";
            var rowLocation = row.getAttribute("data-location") || "";
            var rowStatus = row.getAttribute("data-status") || "";

            var matchesSearch = name.indexOf(searchTerm) !== -1;
            var matchesLocation = location === "all" || rowLocation === location;
            var matchesStatus = status === "all" || rowStatus === status;

            var isVisible = matchesSearch && matchesLocation && matchesStatus;
            row.hidden = !isVisible;

            if (isVisible) visibleCount++;
        });

        if (noResults) {
            noResults.hidden = visibleCount !== 0;
        }
    }

    if (searchInput) searchInput.addEventListener("input", applyFilters);
    if (locationFilter) locationFilter.addEventListener("change", applyFilters);
    if (statusFilter) statusFilter.addEventListener("change", applyFilters);
}

/**
 * Refresh button (UI only).
 */
function initRefreshButton() {
    var refreshBtn = document.getElementById("refreshBtn");
    if (!refreshBtn) return;

    refreshBtn.addEventListener("click", function () {
        // Add spin animation
        refreshBtn.style.transform = "rotate(360deg)";
        refreshBtn.style.transition = "transform 0.6s ease";

        // TODO: Replace with actual data refresh via AJAX
        setTimeout(function () {
            refreshBtn.style.transform = "rotate(0deg)";
        }, 600);
    });
}
