<?php
// admin/inventory-reports.php
// Inventory & Procurement Reports — Admin Portal (UI ONLY).
//
// Per the brief: no backend logic, no database queries beyond the existing
// auth guard. Every figure and row below is static/mock, rendered
// client-side from the REPORTS object at the bottom of this file — same
// structure as pharmacist/reports.php.
//
// SCOPE DECISION: this is deliberately NOT a copy of the pharmacist
// Reports page. That page covers one pharmacist's day-to-day operational
// reports (daily sales, expiry, stock movement). This page covers what
// only admin can see across the whole hospital: purchase request/order
// history across all pharmacists, delivery timeliness, supplier
// performance, and reconciliation discrepancies hospital-wide. Two
// reports below (Inventory Stock Overview, Reconciliation Discrepancy)
// necessarily overlap in subject with the pharmacist page, but at the
// hospital-wide rather than per-shift granularity.
//
// Reached from hospital-reports.php's "Inventory Reports" and
// "Procurement Reports" category cards ("View Reports" buttons), not its
// own sidebar entry — same hub-and-drilldown pattern as Quick Preview on
// that page. $current_page stays 'reports' so the sidebar highlights the
// same nav item as the hub.
//
// SCHEMA GAP (if wired up later): needs purchase_requests, purchase_orders,
// purchase_order_tracking, inventory_reconciliation, inventory_medicines,
// and a suppliers table (doesn't exist yet — supplier_name is currently a
// free-text column on purchase_orders per inventory-procurement.php).

require_once '../includes/auth_guard.php';
require_role('admin');

// UI ONLY — static list backing the six report cards below. No queries.
$reportCards = [
    [
        'key'   => 'purchase-requests',
        'title' => 'Purchase Request History',
        'desc'  => 'Restock requests raised by pharmacists hospital-wide, with approval status.',
        'icon'  => 'file-text',
    ],
    [
        'key'   => 'purchase-orders',
        'title' => 'Purchase Order Summary',
        'desc'  => 'Issued purchase orders across all suppliers, with quantities and current status.',
        'icon'  => 'box',
    ],
    [
        'key'   => 'delivery-timeliness',
        'title' => 'Delivery Timeliness Report',
        'desc'  => 'Expected vs. actual delivery dates per purchase order, flagging delays.',
        'icon'  => 'truck',
    ],
    [
        'key'   => 'supplier-performance',
        'title' => 'Supplier Performance Report',
        'desc'  => 'Fulfillment volume and on-time delivery rate, by supplier.',
        'icon'  => 'chart',
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
    'file-text'      => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line>',
    'box'            => '<path d="M21 8v13H3V8"></path><path d="M1 3h22v5H1z"></path><path d="M10 12h4"></path>',
    'truck'          => '<path d="M10 17h4V5H2v12h3"></path><path d="M20 17h2v-3.34a4 4 0 0 0-1.17-2.83L19 9h-5v8h1"></path><circle cx="7.5" cy="17.5" r="2.5"></circle><circle cx="17.5" cy="17.5" r="2.5"></circle>',
    'chart'          => '<path d="M3 3v18h18"></path><path d="M18 17V9"></path><path d="M13 17V5"></path><path d="M8 17v-3"></path>',
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
    <link rel="stylesheet" href="../assets/css/inventory-procurement.css">
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
                    <p class="page-subtitle">Hospital-wide inventory oversight. Sample data shown &mdash; not yet connected to live records.</p>
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
                        <h2 id="previewTitle">Purchase Request History</h2>
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
        // UI ONLY — mock report data. Status pill classes reuse the exact
        // ones already defined for these statuses elsewhere in the Admin
        // Portal (status-pr-* / status-po-* from inventory-procurement.css,
        // status-match/status-mismatch and status-normal/status-low-stock/
        // status-out-of-stock reproduced in admin-inventory-reports.css)
        // so a status reads identically wherever it's shown. Swap each
        // `rows` array for a real query result when this module is wired
        // up for real.
        // ============================================================
        const REPORTS = {
            'purchase-requests': {
                title: 'Purchase Request History',
                columns: ['Date', 'Medicine', 'Requested By', 'Qty', 'Priority', 'Status'],
                dateColumnIndex: 0,
                rows: [
                    ['Jul 15, 2026', 'Losartan 50mg', 'J. Reyes (Pharmacist)', '50', 'High', '<span class="status-pill status-pr-pending">Pending Approval</span>'],
                    ['Jul 10, 2026', 'Ibuprofen 200mg', 'A. Cruz (Pharmacist)', '100', 'Medium', '<span class="status-pill status-pr-approved">Approved</span>'],
                    ['Jul 8, 2026', 'Insulin Regular (Humulin R)', 'J. Reyes (Pharmacist)', '25', 'Critical', '<span class="status-pill status-pr-revision_requested">Revision Requested</span>'],
                    ['Jul 5, 2026', 'Salbutamol Nebule 2.5mg', 'A. Cruz (Pharmacist)', '40', 'Medium', '<span class="status-pill status-pr-completed">Completed</span>'],
                    ['Jun 28, 2026', 'Cetirizine 10mg', 'J. Reyes (Pharmacist)', '60', 'Low', '<span class="status-pill status-pr-purchase_ordered">Purchase Ordered</span>'],
                    ['Jun 20, 2026', 'Metformin 500mg', 'A. Cruz (Pharmacist)', '35', 'Medium', '<span class="status-pill status-pr-rejected">Rejected</span>'],
                ],
            },
            'purchase-orders': {
                title: 'Purchase Order Summary',
                columns: ['PO Number', 'Medicine', 'Supplier', 'Boxes Ordered', 'Status'],
                dateColumnIndex: null,
                rows: [
                    ['PO-2026-0088', 'Amoxicillin 500mg', 'MedSupply Corp', '12', '<span class="status-pill status-po-awaiting_delivery">Awaiting Delivery</span>'],
                    ['PO-2026-0091', 'Paracetamol 500mg', 'PharmaLink Distributors', '20', '<span class="status-pill status-po-po_issued">PO Issued</span>'],
                    ['PO-2026-0093', 'Losartan 50mg', 'MedSupply Corp', '8', '<span class="status-pill status-po-po_issued">PO Issued</span>'],
                    ['PO-2026-0079', 'Cefalexin 500mg', 'MedSupply Corp', '15', '<span class="status-pill status-po-delivered">Delivered</span>'],
                    ['PO-2026-0082', 'Metformin 500mg', 'PharmaLink Distributors', '30', '<span class="status-pill status-po-completed">Completed</span>'],
                ],
            },
            'delivery-timeliness': {
                title: 'Delivery Timeliness Report',
                columns: ['PO Number', 'Supplier', 'Expected Delivery', 'Actual Delivery', 'Status'],
                dateColumnIndex: null,
                rows: [
                    ['PO-2026-0079', 'MedSupply Corp', 'Jul 16, 2026', 'Jul 17, 2026', '<span class="status-pill status-delayed">Delayed &middot; 1 day</span>'],
                    ['PO-2026-0082', 'PharmaLink Distributors', 'Jul 16, 2026', 'Jul 16, 2026', '<span class="status-pill status-on-time">On Time</span>'],
                    ['PO-2026-0084', 'MedSupply Corp', 'Jul 15, 2026', 'Jul 14, 2026', '<span class="status-pill status-on-time">On Time</span>'],
                    ['PO-2026-0071', 'PharmaLink Distributors', 'Jul 9, 2026', 'Jul 12, 2026', '<span class="status-pill status-delayed">Delayed &middot; 3 days</span>'],
                ],
            },
            'supplier-performance': {
                title: 'Supplier Performance Report',
                columns: ['Supplier', 'POs Fulfilled', 'On-Time Rate', 'Avg. Delay (days)'],
                dateColumnIndex: null,
                rows: [
                    ['MedSupply Corp', '18', '<span class="status-pill status-on-time">89%</span>', '0.4'],
                    ['PharmaLink Distributors', '11', '<span class="status-pill status-delayed">64%</span>', '1.8'],
                ],
            },
            'reconciliation-discrepancy': {
                title: 'Reconciliation Discrepancy Report',
                columns: ['Date', 'Medicine', 'Pharmacist', 'System Stock', 'Physical Count', 'Difference'],
                dateColumnIndex: 0,
                rows: [
                    ['Jul 17, 2026', 'Paracetamol 500mg', 'J. Reyes', '112', '108', '<span class="status-pill status-mismatch">-4 &middot; Mismatch</span>'],
                    ['Jul 17, 2026', 'Cetirizine 10mg', 'J. Reyes', '84', '84', '<span class="status-pill status-match">0 &middot; Match</span>'],
                    ['Jul 10, 2026', 'Losartan 50mg', 'A. Cruz', '30', '30', '<span class="status-pill status-match">0 &middot; Match</span>'],
                    ['Jul 3, 2026', 'Amoxicillin 500mg', 'A. Cruz', '46', '41', '<span class="status-pill status-mismatch">-5 &middot; Mismatch</span>'],
                ],
            },
            'inventory-overview': {
                title: 'Inventory Stock Overview',
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

        let activeKey = 'purchase-requests';

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
        const PDF_STATUS_PILL_CSS = `
            .status-pill { display:inline-block; padding:4px 11px; border-radius:999px; font-size:11.5px; font-weight:600; }
            .status-normal, .status-match, .status-pr-approved, .status-po-delivered, .status-po-completed, .status-on-time { background:#e7f7f0; color:#22a06b; }
            .status-low-stock, .status-pr-pending { background:#fef3e0; color:#f5a524; }
            .status-near-expiry, .status-pr-revision_requested, .status-pr-purchase_ordered, .status-po-po_issued { background:#eaf1ff; color:#2f6fed; }
            .status-po-awaiting_delivery { background:#fef3e0; color:#f5a524; }
            .status-expired, .status-mismatch, .status-pr-rejected, .status-delayed { background:#fdeaea; color:#ef4444; }
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
        <span class="pdf-brand">GabayMed &middot; Admin Portal</span>
    </div>
    <div class="pdf-meta">Generated ${generatedAt} &middot; Sample data (UI preview, not yet connected to live records)</div>
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
