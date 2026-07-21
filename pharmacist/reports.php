<?php
// pharmacist/reports.php
// New Reports module for the Pharmacist Portal — UI ONLY.
//
// Per the brief: no backend logic, no database queries beyond the existing
// auth guard, and nothing here touches inventory/reconciliation/delivery
// data for real. Every figure and row below is static/mock, rendered
// client-side from the REPORTS object at the bottom of this file so the
// six report cards and the preview table share one source of truth.
//
// SCHEMA GAP (if this gets wired up later): a real version would need a
// stock_movement_log (or reuse medicine_exit_log + delivery batches),
// plus the existing inventory_medicines / inventory_reconciliation /
// purchase_requests tables this page's tables are modeled after.
//
// Layout follows the same shell as every other pharmacist page
// (reconciliation.php, inventory.php): shared sidebar, .page-header,
// .card, .queue-table, .status-pill, .toolbar-filters — nothing new was
// introduced except the report-card grid itself (see
// assets/css/pharmacist-dashboard.css, section 11).

require_once '../includes/auth_guard.php';
require_role('pharmacist');

// UI ONLY — static list backing the six report cards below. No queries.
$reportCards = [
    [
        'key'   => 'inventory-stock',
        'title' => 'Inventory Stock Report',
        'desc'  => "Full snapshot of every medicine's current stock against its minimum threshold.",
        'icon'  => 'box',
    ],
    [
        'key'   => 'low-stock',
        'title' => 'Low Stock Report',
        'desc'  => 'Medicines at or below their reorder level, ready for restock review.',
        'icon'  => 'alert-triangle',
    ],
    [
        'key'   => 'expiry',
        'title' => 'Expiry Report',
        'desc'  => 'Batches that are near expiry or already expired, by medicine and batch number.',
        'icon'  => 'calendar',
    ],
    [
        'key'   => 'stock-movement',
        'title' => 'Stock Movement Report',
        'desc'  => 'Chronological log of stock entering (delivery) and leaving (exit scan).',
        'icon'  => 'repeat',
    ],
    [
        'key'   => 'reconciliation',
        'title' => 'Reconciliation Report',
        'desc'  => 'Physical counts compared against system-recorded stock, per medicine.',
        'icon'  => 'check-square',
    ],
    [
        'key'   => 'restock-requests',
        'title' => 'Restock Request History',
        'desc'  => 'Past restock requests raised by the pharmacy and their approval status.',
        'icon'  => 'file-text',
    ],
    [
        'key'   => 'daily-sales',
        'title' => 'Daily Sales / Sold-Out Report',
        'desc'  => "End-of-day dispensing totals per medicine, flagging anything that sold out that day.",
        'icon'  => 'trending-down',
    ],
];

// NOTE: named $reportIcons (not $icons) — includes/sidebar.php declares
// its own $icons array for the nav SVGs, and since the sidebar is
// included further down (before the cards loop below runs), a same-named
// variable here would get silently overwritten by the sidebar's icon set.
$reportIcons = [
    'box'           => '<path d="M21 8v13H3V8"></path><path d="M1 3h22v5H1z"></path><path d="M10 12h4"></path>',
    'alert-triangle' => '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line>',
    'calendar'      => '<rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line>',
    'repeat'        => '<polyline points="17 1 21 5 17 9"></polyline><path d="M3 11V9a4 4 0 0 1 4-4h14"></path><polyline points="7 23 3 19 7 15"></polyline><path d="M21 13v2a4 4 0 0 1-4 4H3"></path>',
    'check-square'  => '<polyline points="9 11 12 14 22 4"></polyline><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path>',
    'file-text'     => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line>',
    'trending-down' => '<polyline points="23 18 13.5 8.5 8.5 13.5 1 6"></polyline><polyline points="17 18 23 18 23 12"></polyline>',
];

$current_page = 'reports';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - GabayMed</title>
    <link rel="stylesheet" href="../assets/css/doctor-dashboard.css">
    <link rel="stylesheet" href="../assets/css/pharmacist-dashboard.css">
</head>

<body>

    <div class="app-shell">

        <?php include 'includes/sidebar.php'; ?>

        <!-- Main content -->
        <main class="main-content">

            <header class="page-header">
                <div>
                    <h1>Reports</h1>
                    <p class="page-subtitle">Preview and export pharmacy reports. Sample data shown &mdash; not yet connected to live inventory.</p>
                </div>
            </header>

            <!-- Filters -->
            <section class="card reports-filter-bar">
                <div class="card-header">
                    <h2>Filters</h2>
                    <span class="card-subtitle">UI preview only</span>
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
                        <h2 id="previewTitle">Inventory Stock Report</h2>
                        <span class="card-subtitle" id="previewSubtitle">Sample data</span>
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
        // UI ONLY — mock report data. Column labels and rows below match
        // the shapes already used elsewhere in the Pharmacist Portal
        // (inventory.php's status pills, reconciliation.php's Match/
        // Mismatch pills, the purchase_requests status pills used for
        // Restock Request History) so nothing here needed a new visual
        // vocabulary. Swap each `rows` array for a real query result
        // when this module is wired up for real.
        // ============================================================
        const REPORTS = {
            'inventory-stock': {
                title: 'Inventory Stock Report',
                columns: ['Medicine', 'Current Stock', 'Minimum Stock', 'Status'],
                dateColumnIndex: null,
                rows: [
                    ['Amoxicillin 500mg', '46', '30', '<span class="status-pill status-normal">Normal</span>'],
                    ['Paracetamol 500mg', '112', '40', '<span class="status-pill status-normal">Normal</span>'],
                    ['Losartan 50mg', '18', '25', '<span class="status-pill status-low-stock">Low Stock</span>'],
                    ['Metformin 500mg', '58', '30', '<span class="status-pill status-normal">Normal</span>'],
                    ['Ibuprofen 200mg', '0', '20', '<span class="status-pill status-out-of-stock">Out of Stock</span>'],
                    ['Cetirizine 10mg', '84', '25', '<span class="status-pill status-normal">Normal</span>'],
                ],
            },
            'low-stock': {
                title: 'Low Stock Report',
                columns: ['Medicine', 'Current Stock', 'Reorder Level'],
                dateColumnIndex: null,
                rows: [
                    ['Losartan 50mg', '18', '25'],
                    ['Ibuprofen 200mg', '0', '20'],
                    ['Salbutamol Nebule 2.5mg', '6', '15'],
                ],
            },
            'expiry': {
                title: 'Expiry Report',
                columns: ['Medicine', 'Batch No.', 'Expiry Date', 'Status'],
                dateColumnIndex: null,
                rows: [
                    ['Insulin Regular (Humulin R)', 'BN-2026-0512', 'Jul 25, 2026', '<span class="status-pill status-near-expiry">Near Expiry</span>'],
                    ['Salbutamol Nebule 2.5mg', 'BN-2026-0388', 'Jun 30, 2026', '<span class="status-pill status-expired">Expired</span>'],
                    ['Losartan 50mg', 'BN-2026-0611', 'Aug 5, 2026', '<span class="status-pill status-near-expiry">Near Expiry</span>'],
                    ['Amoxicillin 500mg', 'BN-2027-0142', 'Mar 15, 2027', '<span class="status-pill status-normal">Normal</span>'],
                ],
            },
            'stock-movement': {
                title: 'Stock Movement Report',
                columns: ['Date', 'Medicine', 'Action', 'Quantity', 'User'],
                dateColumnIndex: 0,
                rows: [
                    ['Jul 17, 2026', 'Amoxicillin 500mg', 'Storage Exit Scan', '-10', 'J. Santos'],
                    ['Jul 16, 2026', 'Paracetamol 500mg', 'Delivery Received', '+50', 'M. Dizon'],
                    ['Jul 16, 2026', 'Losartan 50mg', 'Storage Exit Scan', '-5', 'J. Santos'],
                    ['Jul 15, 2026', 'Cetirizine 10mg', 'Delivery Received', '+30', 'M. Dizon'],
                    ['Jul 14, 2026', 'Ibuprofen 200mg', 'Storage Exit Scan', '-20', 'J. Santos'],
                ],
            },
            'reconciliation': {
                title: 'Reconciliation Report',
                columns: ['Medicine', 'System Stock', 'Physical Count', 'Difference'],
                dateColumnIndex: null,
                rows: [
                    ['Cetirizine 10mg', '84', '84', '<span class="status-pill status-match">0 &middot; Match</span>'],
                    ['Paracetamol 500mg', '112', '108', '<span class="status-pill status-mismatch">-4 &middot; Mismatch</span>'],
                    ['Losartan 50mg', '30', '30', '<span class="status-pill status-match">0 &middot; Match</span>'],
                ],
            },
            'restock-requests': {
                title: 'Restock Request History',
                columns: ['Date', 'Medicine', 'Requested Qty', 'Status'],
                dateColumnIndex: 0,
                rows: [
                    ['Jul 15, 2026', 'Losartan 50mg', '50', '<span class="status-pill status-pr-pending">Pending Approval</span>'],
                    ['Jul 10, 2026', 'Ibuprofen 200mg', '100', '<span class="status-pill status-pr-approved">Approved</span>'],
                    ['Jul 5, 2026', 'Salbutamol Nebule 2.5mg', '40', '<span class="status-pill status-pr-completed">Completed</span>'],
                    ['Jun 28, 2026', 'Insulin Regular (Humulin R)', '25', '<span class="status-pill status-pr-rejected">Rejected</span>'],
                ],
            },
            // Answers the panel's Q6: "what report does the pharmacist
            // produce at end of day to determine sold-out medicines?"
            // One row per medicine per day. A medicine is flagged Sold
            // Out the day its Remaining Stock hits 0, same status-pill
            // classes as Inventory Stock Report so no new CSS/PDF styling
            // was needed for this card.
            'daily-sales': {
                title: 'Daily Sales / Sold-Out Report',
                columns: ['Date', 'Medicine', 'Quantity Dispensed', 'Remaining Stock', 'Status'],
                dateColumnIndex: 0,
                rows: [
                    ['Jul 19, 2026', 'Amoxicillin 500mg', '12', '34', '<span class="status-pill status-normal">Normal</span>'],
                    ['Jul 19, 2026', 'Ibuprofen 200mg', '20', '0', '<span class="status-pill status-out-of-stock">Sold Out</span>'],
                    ['Jul 19, 2026', 'Paracetamol 500mg', '31', '81', '<span class="status-pill status-normal">Normal</span>'],
                    ['Jul 18, 2026', 'Salbutamol Nebule 2.5mg', '15', '0', '<span class="status-pill status-out-of-stock">Sold Out</span>'],
                    ['Jul 18, 2026', 'Cetirizine 10mg', '9', '75', '<span class="status-pill status-normal">Normal</span>'],
                    ['Jul 17, 2026', 'Losartan 50mg', '8', '10', '<span class="status-pill status-normal">Normal</span>'],
                ],
            },
        };

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

        let activeKey = 'inventory-stock';

        // Renders REPORTS[key] into the preview table, then re-applies the
        // current search/date filters so switching reports never leaves a
        // stale filter state showing rows from the previous table.
        function renderReport(key) {
            const report = REPORTS[key];
            if (!report) return;
            activeKey = key;

            if (reportTypeFilter.value !== key) reportTypeFilter.value = key;

            previewTitle.textContent = report.title;
            previewSubtitle.textContent = `${report.rows.length} sample record${report.rows.length === 1 ? '' : 's'}`;

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
        // only applies to reports that actually have a date column —
        // Inventory Stock, Low Stock, Expiry and Reconciliation don't, so
        // it's a no-op for those, same as leaving the field blank).
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

        // Exports the currently-active report as a PDF — per the
        // instructor's note, CSV/Excel exports can be edited by anyone,
        // which defeats the point of a report meant to be a fixed record.
        // No PDF library is added (per the "no external libraries"
        // requirement): this opens a clean, sidebar-free, read-only
        // version of just that report's title + table in a new tab and
        // triggers the browser's native print dialog, where the person
        // picks "Save as PDF" as the destination. That's the same
        // mechanism browsers already use for Print, so it needs nothing
        // beyond what's already loaded.
        const PDF_STATUS_PILL_CSS = `
            .status-pill { display:inline-block; padding:4px 11px; border-radius:999px; font-size:11.5px; font-weight:600; }
            .status-normal, .status-match, .status-pr-approved { background:#e7f7f0; color:#22a06b; }
            .status-low-stock, .status-pr-pending { background:#fef3e0; color:#f5a524; }
            .status-near-expiry, .status-pr-revision_requested, .status-pr-purchase_ordered { background:#eaf1ff; color:#2f6fed; }
            .status-expired, .status-mismatch, .status-pr-rejected { background:#fdeaea; color:#ef4444; }
            .status-out-of-stock { background:#ef4444; color:#ffffff; }
            .status-pr-completed { background:#eef1f4; color:#6b7785; }
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
        <span class="pdf-brand">GabayMed &middot; Pharmacist Portal</span>
    </div>
    <div class="pdf-meta">Generated ${generatedAt} &middot; Sample data (UI preview, not yet connected to live inventory)</div>
    <table>
        <thead>${theadHtml}</thead>
        <tbody>${rowsHtml}</tbody>
    </table>
    <div class="pdf-footer">This is a read-only exported record. To make changes, update the source data and export again.</div>
</body>
</html>`);
            win.document.close();

            // document.write() above is synchronous, but give the new
            // tab's layout a beat before opening the print dialog so
            // "Save as PDF" captures the fully-rendered table.
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