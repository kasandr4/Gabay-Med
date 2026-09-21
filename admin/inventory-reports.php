<?php
// admin/inventory-reports.php
// Inventory Reports — Admin Portal.
//
// SCOPE DECISION: this is deliberately NOT a copy of the pharmacist
// Reports page. That page covers one pharmacist's day-to-day operational
// reports (daily sales, expiry, stock movement). This page covers what
// only admin can see across the whole hospital: stock received hospital-
// wide (all funding sources, all pharmacists), and reconciliation
// discrepancies hospital-wide. Two reports below (Inventory Stock
// Overview, Reconciliation Discrepancy) necessarily overlap in subject
// with the pharmacist page, but at the hospital-wide rather than
// per-shift granularity.
//
// Reached from hospital-reports.php's "Inventory Reports" category card
// ("View Reports" button), not its own sidebar entry — same
// hub-and-drilldown pattern as Quick Preview on that page. $current_page
// stays 'reports' so the sidebar highlights the same nav item as the hub.
//
// FIXED (2026-08-22): Purchase Request History, Purchase Order Summary,
// Delivery Timeliness, and Supplier Performance all queried tables
// dropped by 018_replace_procurement_with_funding_source.sql. None of
// them have a faithful 1:1 replacement — there's no more "request",
// "PO", "supplier", or "expected vs. actual delivery date" concept in
// the funding-source model. Purchase Request History and Supplier
// Performance are removed outright (nothing to honestly replace them
// with). Purchase Order Summary + Delivery Timeliness are merged into
// one real replacement below, Stock Received Report — hospital-wide
// medicine_batches history with funding source, which is the actual
// current equivalent of "what stock came in and how."
//
// One correction versus the old mock this file used to carry: Purchase
// Request History used to include a "Purchase Ordered" example status
// that never occurred in real data — moot now that the whole report is
// gone, noted here only so the history of that decision isn't lost.
//
// Still needs a real suppliers table if Stock Received Report grows
// further — funding source is currently just the 5-value enum on
// medicine_batches, no dedicated supplier/donor entity.

require_once '../includes/auth_guard.php';
require_once '../config/db.php'; // provides $conn (mysqli connection)
require_role('admin');

$statusPillHtml = function (string $class, string $label): string {
    return '<span class="status-pill ' . $class . '">' . htmlspecialchars($label) . '</span>';
};

// ---------- 1. Stock Received Report ----------
// Replaces the old Purchase Order Summary + Delivery Timeliness reports
// — see FIXED note above. Hospital-wide, every batch any pharmacist has
// recorded via Record Stock Batch, regardless of funding source.
$sourceLabels = [
    'maip'           => 'MAIP',
    'philhealth'     => 'PhilHealth',
    'pho'            => 'PHO',
    'purchase_order' => 'Purchase Order',
    'donated'        => 'Donated',
];
$stockReceivedRows = [];
$res = $conn->query(
    "SELECT mb.received_at, im.name AS medicine, mb.source, mb.units_received,
            CONCAT(u.first_name, ' ', u.last_name) AS recorded_by
     FROM medicine_batches mb
     JOIN inventory_medicines im ON im.medicine_id = mb.medicine_id
     JOIN users u ON u.user_id = mb.created_by
     ORDER BY mb.received_at DESC"
);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $stockReceivedRows[] = [
            date('M j, Y', strtotime($row['received_at'])),
            $row['medicine'],
            $statusPillHtml('status-source-' . $row['source'], $sourceLabels[$row['source']] ?? ucfirst($row['source'])),
            (string) (int) $row['units_received'],
            $row['recorded_by'],
        ];
    }
}

// ---------- 2. Reconciliation Discrepancy Report ----------
$reconciliationRows = [];
$stmt = $conn->prepare(
    "SELECT icb.confirmed_at, im.name AS medicine,
            CONCAT(u.first_name, ' ', u.last_name) AS pharmacist_name,
            ici.system_count, ici.final_count
     FROM inventory_count_batches icb
     JOIN inventory_count_items ici ON ici.batch_id = icb.batch_id
     JOIN inventory_medicines im ON im.medicine_id = ici.medicine_id
     JOIN users u ON u.user_id = icb.assigned_by
     WHERE icb.status = 'confirmed' AND ici.final_count IS NOT NULL
     ORDER BY icb.confirmed_at DESC, im.name ASC"
);
$stmt->execute();
$res = $stmt->get_result();
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $difference = (int) $row['final_count'] - (int) $row['system_count'];
        $diffLabel = ($difference >= 0 ? '+' : '') . $difference;
        if ($difference !== 0) {
            $statusHtml = $statusPillHtml('status-mismatch', $diffLabel . ' - Mismatch');
        } else {
            $statusHtml = $statusPillHtml('status-match', '0 - Match');
        }
        $reconciliationRows[] = [
            date('M j, Y', strtotime($row['confirmed_at'])),
            $row['medicine'],
            $row['pharmacist_name'],
            (string) (int) $row['system_count'],
            (string) (int) $row['final_count'],
            $statusHtml,
        ];
    }
}
$stmt->close();

// ---------- 3. Inventory Stock Overview ----------
$inventoryOverviewRows = [];
$res = $conn->query("SELECT name, current_stock, minimum_stock FROM inventory_medicines ORDER BY name ASC");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $current = (int) $row['current_stock'];
        $minimum = (int) $row['minimum_stock'];
        if ($current <= 0) {
            $statusHtml = $statusPillHtml('status-out-of-stock', 'Out of Stock');
        } elseif ($current <= $minimum) {
            $statusHtml = $statusPillHtml('status-low-stock', 'Low Stock');
        } else {
            $statusHtml = $statusPillHtml('status-normal', 'Normal');
        }
        $inventoryOverviewRows[] = [
            $row['name'],
            (string) $current,
            (string) $minimum,
            $statusHtml,
        ];
    }
}

$reportsData = [
    'stock-received' => [
        'title' => 'Stock Received Report',
        'columns' => ['Date', 'Medicine', 'Funding Source', 'Quantity', 'Recorded By'],
        'dateColumnIndex' => 0,
        'rows' => $stockReceivedRows,
    ],
    'reconciliation-discrepancy' => [
        'title' => 'Reconciliation Discrepancy Report',
        'columns' => ['Date', 'Medicine', 'Pharmacist', 'System Stock', 'Physical Count', 'Difference'],
        'dateColumnIndex' => 0,
        'rows' => $reconciliationRows,
    ],
    'inventory-overview' => [
        'title' => 'Inventory Stock Overview',
        'columns' => ['Medicine', 'Current Stock', 'Minimum Stock', 'Status'],
        'dateColumnIndex' => null,
        'rows' => $inventoryOverviewRows,
    ],
];

// UI ONLY — static list backing the report cards below. No queries.
$reportCards = [
    [
        'key'   => 'stock-received',
        'title' => 'Stock Received Report',
        'desc'  => 'Every stock batch recorded hospital-wide, by funding source (MAIP, PhilHealth, PHO, Purchase Order, Donated).',
        'icon'  => 'box',
    ],
    [
        'key'   => 'reconciliation-discrepancy',
        'title' => 'Reconciliation Discrepancy Report',
        'desc'  => 'Physical count mismatches across all pharmacists and reconciliation periods.',
        'icon'  => 'check-square',
    ],
    [
        'key'   => 'inventory-overview',
        'title' => 'Inventory Stock Overview',
        'desc'  => 'Hospital-wide snapshot of current stock against minimum thresholds.',
        'icon'  => 'alert-triangle',
    ],
];

// NOTE: named $reportIcons (not $icons) — includes/sidebar.php declares
// its own $icons array for the nav SVGs, and since the sidebar is
// included further down, a same-named variable here would get silently
// overwritten by the sidebar's icon set.
$reportIcons = [
    'box'            => '<path d="M21 8v13H3V8"></path><path d="M1 3h22v5H1z"></path><path d="M10 12h4"></path>',
    'check-square'   => '<polyline points="9 11 12 14 22 4"></polyline><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path>',
    'alert-triangle' => '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line>',
];

$current_page = 'reports';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory & Procurement Reports - GabayMed</title>
    <link rel="stylesheet" href="../assets/css/doctor-dashboard.css">
    <link rel="stylesheet" href="../assets/css/admin-dashboard.css">
    <link rel="stylesheet" href="../assets/css/admin-inventory-reports.css">
</head>

<body>

    <div class="app-shell">

        <?php include 'includes/sidebar.php'; ?>

        <!-- Main content -->
        <main class="main-content">

            <header class="page-header">
                <div>
                    <a href="hospital-reports.php" class="reports-back-link">&larr; Hospital Reports</a>
                    <h1>Inventory &amp; Procurement Reports</h1>
                    <p class="page-subtitle">Hospital-wide inventory oversight, live from purchase requests, orders, deliveries, and reconciliation records.</p>
                </div>
            </header>

            <!-- Filters -->
            <section class="card reports-filter-bar">
                <div class="card-header">
                    <h2>Filters</h2>
                    <span class="card-subtitle">Filters apply to the report currently shown below</span>
                </div>
                <div class="toolbar-filters">
                    <select class="filter-select" id="reportTypeFilter" aria-label="Report Type">
                        <?php foreach ($reportCards as $rc): ?>
                            <option value="<?php echo htmlspecialchars($rc['key']); ?>"><?php echo htmlspecialchars($rc['title']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="date" class="filter-select" id="dateFrom" aria-label="Date From">
                    <input type="date" class="filter-select" id="dateTo" aria-label="Date To">
                    <input type="text" class="inventory-search" id="reportSearch" placeholder="Search this report&hellip;" aria-label="Search">
                </div>
            </section>

            <!-- Report cards -->
            <section class="reports-grid">
                <?php foreach ($reportCards as $rc): ?>
                    <div class="card report-card" data-report-key="<?php echo htmlspecialchars($rc['key']); ?>">
                        <div class="report-card-icon">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <?php echo $reportIcons[$rc['icon']]; ?>
                            </svg>
                        </div>
                        <h3 class="report-card-title"><?php echo htmlspecialchars($rc['title']); ?></h3>
                        <p class="report-card-desc"><?php echo htmlspecialchars($rc['desc']); ?></p>
                        <div class="report-card-actions">
                            <button type="button" class="btn btn-primary btn-sm" data-action="view">View</button>
                            <button type="button" class="btn btn-secondary btn-sm" data-action="print">Print</button>
                            <button type="button" class="btn btn-secondary btn-sm" data-action="export">Export PDF</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </section>

            <!-- Report preview -->
            <section class="card" id="reportPreviewCard">
                <div class="card-header">
                    <div>
                        <h2 id="previewTitle">Purchase Request History</h2>
                        <span class="card-subtitle" id="previewSubtitle">Loading&hellip;</span>
                    </div>
                    <div class="report-preview-header-actions">
                        <button type="button" class="btn btn-secondary btn-sm" id="previewPrintBtn">Print</button>
                        <button type="button" class="btn btn-secondary btn-sm" id="previewExportBtn">Export PDF</button>
                    </div>
                </div>
                <div class="table-wrap">
                    <table class="queue-table" id="previewTable">
                        <thead id="previewThead"></thead>
                        <tbody id="previewTbody"></tbody>
                    </table>
                </div>
                <div class="empty-state" id="previewEmptyState" hidden>
                    <p>No rows match your filters</p>
                </div>
            </section>

            <footer class="page-footer">
                <p>&copy; <?php echo date("Y"); ?> GabayMed Hospital Management System</p>
            </footer>

        </main>
    </div>

    <script>
        // ============================================================
        // BUG FIX (2026-07-23): this used to be a hardcoded REPORTS object,
        // completely disconnected from the six real queries that build
        // $reportsData near the top of this file. The header comment
        // claimed those queries were already wired in — they were real
        // queries, but nothing ever sent their results here, so this
        // block was still 100% mock data (it even still had the fake
        // 'Purchase Ordered' status the header comment claimed was
        // already removed). Now genuinely wired: $reportsData is
        // json_encode'd straight into this constant, so the shape below
        // is real hospital data, not a copy of it.
        const REPORTS = <?php echo json_encode($reportsData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

        const reportTypeFilter = document.getElementById('reportTypeFilter');
        const dateFrom = document.getElementById('dateFrom');
        const dateTo = document.getElementById('dateTo');
        const reportSearch = document.getElementById('reportSearch');
        const previewTitle = document.getElementById('previewTitle');
        const previewSubtitle = document.getElementById('previewSubtitle');
        const previewThead = document.getElementById('previewThead');
        const previewTbody = document.getElementById('previewTbody');
        const previewTable = document.getElementById('previewTable');
        const previewEmptyState = document.getElementById('previewEmptyState');

        let activeKey = 'stock-received';

        // Renders REPORTS[key] into the preview table, then re-applies the
        // current search/date filters so switching reports never leaves a
        // stale filter state showing rows from the previous table.
        function renderReport(key) {
            const report = REPORTS[key];
            if (!report) return;
            activeKey = key;

            if (reportTypeFilter.value !== key) reportTypeFilter.value = key;

            previewTitle.textContent = report.title;
            previewSubtitle.textContent = `${report.rows.length} record${report.rows.length === 1 ? '' : 's'}`;

            previewThead.innerHTML = '<tr>' + report.columns.map(c => `<th>${c}</th>`).join('') + '</tr>';
            previewTbody.innerHTML = report.rows.map(row => {
                const dateAttr = report.dateColumnIndex !== null ?
                    ` data-date="${toIsoDate(row[report.dateColumnIndex])}"` :
                    '';
                return `<tr${dateAttr}>` + row.map(cell => `<td>${cell}</td>`).join('') + '</tr>';
            }).join('');

            applyFilters();
        }

        // Converts the mock "Jul 17, 2026" style strings to YYYY-MM-DD so
        // they can be compared against <input type="date"> values.
        function toIsoDate(label) {
            const d = new Date(label);
            if (isNaN(d.getTime())) return '';
            return d.toISOString().slice(0, 10);
        }

        function stripHtml(str) {
            return str.replace(/<[^>]+>/g, '').replace(/&middot;/g, '\u00b7').trim();
        }

        // Combines the free-text search with the date range (date range
        // only applies to reports that actually have a date column — PO
        // Summary, Delivery Timeliness, Supplier Performance and
        // Inventory Overview don't, so it's a no-op for those).
        function applyFilters() {
            const term = reportSearch.value.trim().toLowerCase();
            const from = dateFrom.value;
            const to = dateTo.value;
            const rows = previewTbody.querySelectorAll('tr');
            let visibleCount = 0;

            rows.forEach(row => {
                const matchesText = !term || stripHtml(row.textContent).toLowerCase().includes(term);

                let matchesDate = true;
                const rowDate = row.dataset.date;
                if (rowDate) {
                    if (from && rowDate < from) matchesDate = false;
                    if (to && rowDate > to) matchesDate = false;
                }

                const visible = matchesText && matchesDate;
                row.style.display = visible ? '' : 'none';
                if (visible) visibleCount++;
            });

            previewTable.hidden = visibleCount === 0 && rows.length > 0;
            previewEmptyState.hidden = !(visibleCount === 0 && rows.length > 0);
        }

        // Exports the currently-active report as a PDF — same mechanism as
        // pharmacist/reports.php: no PDF library, opens a clean read-only
        // version in a new tab and triggers the browser's native print
        // dialog, where the person picks "Save as PDF" as the destination.
        //
        // FIXED (2026-08-22): status-pr-*/status-po-* selectors here were
        // for Purchase Request History / Purchase Order Summary, both
        // removed along with the rest of the procurement pipeline (see
        // 018_replace_procurement_with_funding_source.sql). Replaced with
        // status-source-* for Stock Received Report's funding-source
        // badges — same 5 values as $sourceLabels above.
        const PDF_STATUS_PILL_CSS = `
            .status-pill { display:inline-block; padding:4px 11px; border-radius:999px; font-size:11.5px; font-weight:600; }
            .status-normal, .status-match, .status-on-time { background:#e7f7f0; color:#22a06b; }
            .status-low-stock { background:#fef3e0; color:#f5a524; }
            .status-near-expiry { background:#eaf1ff; color:#2f6fed; }
            .status-expired, .status-mismatch, .status-delayed { background:#fdeaea; color:#ef4444; }
            .status-out-of-stock { background:#ef4444; color:#ffffff; }
            .status-source-maip, .status-source-philhealth, .status-source-pho { background:#eaf1ff; color:#2f6fed; }
            .status-source-purchase_order { background:#fef3e0; color:#f5a524; }
            .status-source-donated { background:#e7f7f0; color:#22a06b; }
        `;

        function exportPdf(key) {
            const report = REPORTS[key];
            if (!report) return;

            const theadHtml = '<tr>' + report.columns.map(c => `<th>${c}</th>`).join('') + '</tr>';
            const rowsHtml = report.rows.map(row => '<tr>' + row.map(cell => `<td>${cell}</td>`).join('') + '</tr>').join('');
            const generatedAt = new Date().toLocaleString('en-US', {
                dateStyle: 'medium',
                timeStyle: 'short'
            });

            const win = window.open('', '_blank', 'width=960,height=720');
            if (!win) {
                alert('Please allow pop-ups for this site to export a PDF.');
                return;
            }

            win.document.write(`<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>${report.title} - GabayMed</title>
<style>
    body { font-family: "Segoe UI", Roboto, -apple-system, BlinkMacSystemFont, sans-serif; color:#1c2733; margin:0; padding:36px; }
    .pdf-header { display:flex; align-items:baseline; justify-content:space-between; border-bottom:2px solid #14b8a6; padding-bottom:12px; margin-bottom:6px; }
    .pdf-header h1 { margin:0; font-size:19px; color:#0f9c8d; }
    .pdf-brand { font-size:12px; font-weight:700; color:#1c2733; }
    .pdf-meta { font-size:12px; color:#6b7785; margin-bottom:22px; }
    table { width:100%; border-collapse:collapse; font-size:12.5px; }
    th, td { text-align:left; padding:9px 10px; border-bottom:1px solid #e7eaee; }
    th { background:#e6fbf8; color:#0f9c8d; font-weight:700; }
    ${PDF_STATUS_PILL_CSS}
    .pdf-footer { margin-top:26px; font-size:11px; color:#98a2ae; }
</style>
</head>
<body>
    <div class="pdf-header">
        <h1>${report.title}</h1>
        <span class="pdf-brand">GabayMed &middot; Admin Portal</span>
    </div>
    <div class="pdf-meta">Generated ${generatedAt} &middot; GabayMed Hospital Management System</div>
    <table>
        <thead>${theadHtml}</thead>
        <tbody>${rowsHtml}</tbody>
    </table>
    <div class="pdf-footer">This is a read-only exported record. To make changes, update the source data and export again.</div>
</body>
</html>`);
            win.document.close();

            win.focus();
            setTimeout(() => {
                try {
                    win.print();
                } catch (e) {}
            }, 200);
        }

        function printReport(key) {
            renderReport(key);
            window.print();
        }

        // Top filter bar
        reportTypeFilter.addEventListener('change', () => renderReport(reportTypeFilter.value));
        reportSearch.addEventListener('input', applyFilters);
        dateFrom.addEventListener('change', applyFilters);
        dateTo.addEventListener('change', applyFilters);

        // Preview card's own Print / Export PDF (act on whatever is shown)
        document.getElementById('previewPrintBtn').addEventListener('click', () => printReport(activeKey));
        document.getElementById('previewExportBtn').addEventListener('click', () => exportPdf(activeKey));

        // Per-card View / Print / Export PDF
        document.querySelectorAll('.report-card').forEach(card => {
            const key = card.dataset.reportKey;
            card.querySelectorAll('[data-action]').forEach(btn => {
                btn.addEventListener('click', () => {
                    const action = btn.dataset.action;
                    if (action === 'view') {
                        renderReport(key);
                        document.getElementById('reportPreviewCard').scrollIntoView({
                            behavior: 'smooth',
                            block: 'start'
                        });
                    } else if (action === 'print') {
                        printReport(key);
                    } else if (action === 'export') {
                        exportPdf(key);
                    }
                });
            });
        });

        // Initial render
        renderReport(activeKey);
    </script>
</body>

</html>