<?php
// pharmacist/reports.php
// Single Reports hub for the pharmacist portal, split into tabs:
//   - inventory:   saved Inventory Count snapshots (existing, unchanged)
//   - dispensing:  monthly dispensing activity + fast-moving medicines
//   - backup:      backup downloads + the "Archive Selected Data" action
//   - archive:     lists past archive runs, lets you restore any of them
// Each tab's data is only queried when that tab is active.
//
// "Archive" (not "Reset"/"Delete"): per instructor feedback, this system
// never permanently erases data. backup-restore-actions.php copies
// affected rows into archived_* tables before clearing the live ones, so
// every run can be restored in full from the archive tab below.

require_once '../includes/auth_guard.php';
require_role('pharmacist');
require_once '../config/db.php';
require_once '../includes/csrf.php';
require_once 'includes/monthly_report_helpers.php';

$pharmacistId = (int) $_SESSION['user_id'];

$tab = $_GET['tab'] ?? 'inventory';
if (!in_array($tab, ['inventory', 'monthly', 'backup', 'archive'], true)) {
    $tab = 'inventory';
}

// =====================================================================
// Tab: Inventory Counts (existing behavior, unchanged)
// =====================================================================
$reports = [];
if ($tab === 'inventory') {
    $stmt = $conn->prepare(
        "SELECT r.report_id, r.report_name, r.saved_at, r.total_items, r.total_count,
                CONCAT(u.first_name, ' ', u.last_name) AS saved_by
         FROM inventory_count_reports r
         JOIN users u ON u.user_id = r.saved_by
         WHERE r.saved_by = ?
         ORDER BY r.saved_at DESC, r.report_id DESC"
    );
    $stmt->bind_param('i', $pharmacistId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) $reports[] = $row;
    $stmt->close();
}

// =====================================================================
// Tab: Dispensing (monthly activity + fast-moving medicines)
// =====================================================================
// monthBounds() and sumByMedicine() now live in includes/monthly_report_helpers.php.

/**
 * Headline stats + top medicine for one month range. Reused for both the
 * selected-month cards and each row of the monthly history table.
 */
function getMonthDispenseStats($conn, $monthStart, $monthEnd)
{
    $stats = ['units' => 0, 'transactions' => 0, 'distinct_medicines' => 0, 'distinct_staff' => 0, 'top_medicine' => null, 'top_medicine_units' => 0];

    $stmt = $conn->prepare(
        "SELECT COALESCE(SUM(units_deducted), 0) AS units, COUNT(*) AS transactions,
                COUNT(DISTINCT medicine_id) AS distinct_medicines, COUNT(DISTINCT staff_id) AS distinct_staff
         FROM medicine_exit_log
         WHERE scanned_at BETWEEN ? AND ?"
    );
    $stmt->bind_param('ss', $monthStart, $monthEnd);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $stats['units'] = (int) $row['units'];
    $stats['transactions'] = (int) $row['transactions'];
    $stats['distinct_medicines'] = (int) $row['distinct_medicines'];
    $stats['distinct_staff'] = (int) $row['distinct_staff'];

    $stmt = $conn->prepare(
        "SELECT im.name, SUM(mel.units_deducted) AS units
         FROM medicine_exit_log mel
         JOIN inventory_medicines im ON im.medicine_id = mel.medicine_id
         WHERE mel.scanned_at BETWEEN ? AND ?
         GROUP BY mel.medicine_id, im.name
         ORDER BY units DESC
         LIMIT 1"
    );
    $stmt->bind_param('ss', $monthStart, $monthEnd);
    $stmt->execute();
    $top = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($top) {
        $stats['top_medicine'] = $top['name'];
        $stats['top_medicine_units'] = (int) $top['units'];
    }

    return $stats;
}

$currentMonthKey = date('Y-m');
$mrParams = monthlyReportParams($_GET, $currentMonthKey);
$reportMonthKey = $mrParams['month'];
$reportMonthLabel = date('F Y', strtotime($reportMonthKey . '-01'));

// Month picker options (pure date math, no query). A month picked through the URL
// that falls outside the last 12 months is added so the select always shows it.
$monthOptions = [];
for ($i = 0; $i < 12; $i++) {
    $mk = date('Y-m', strtotime("$currentMonthKey-01 -$i month"));
    $monthOptions[] = ['month_key' => $mk, 'month' => date('F Y', strtotime($mk . '-01'))];
}
if (!in_array($reportMonthKey, array_column($monthOptions, 'month_key'), true)) {
    $monthOptions[] = ['month_key' => $reportMonthKey, 'month' => date('F Y', strtotime($reportMonthKey . '-01'))];
}

$dispenseMonthlyHistory = [];
$fastMovers = [];
$selectedDispenseStats = ['units' => 0, 'transactions' => 0, 'distinct_medicines' => 0, 'distinct_staff' => 0, 'top_medicine' => null, 'top_medicine_units' => 0];
$mrData = null;
$mrPage = null;

if ($tab === 'monthly') {
    [$selectedMonthStart, $selectedMonthEnd] = monthBounds($reportMonthKey);
    $selectedDispenseStats = getMonthDispenseStats($conn, $selectedMonthStart, $selectedMonthEnd);

    $stmt = $conn->prepare(
        "SELECT im.name, SUM(mel.units_deducted) AS units
         FROM medicine_exit_log mel
         JOIN inventory_medicines im ON im.medicine_id = mel.medicine_id
         WHERE mel.scanned_at BETWEEN ? AND ?
         GROUP BY mel.medicine_id, im.name
         ORDER BY units DESC
         LIMIT 5"
    );
    $stmt->bind_param('ss', $selectedMonthStart, $selectedMonthEnd);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $fastMovers[] = ['name' => $row['name'], 'units' => (int) $row['units']];
    }
    $stmt->close();

    for ($i = 0; $i < 6; $i++) {
        $monthKey = date('Y-m', strtotime("$currentMonthKey-01 -$i month"));
        [$mStart, $mEnd] = monthBounds($monthKey);
        $mStats = getMonthDispenseStats($conn, $mStart, $mEnd);
        $dispenseMonthlyHistory[] = [
            'month_key' => $monthKey,
            'month' => date('F Y', strtotime($mStart)),
            'units' => $mStats['units'],
            'transactions' => $mStats['transactions'],
            'top_medicine' => $mStats['top_medicine'],
            'status' => $monthKey < $currentMonthKey ? 'Finalized' : 'In Progress',
        ];
    }

    // Stock snapshot for the report month,
    // then search / filter / sort / paging for the breakdown table.
    $mrData = monthlyReportBuild($conn, $mrParams, $currentMonthKey);
    $mrPage = monthlyReportPaginate($mrData['rows'], $mrParams['page'], 25);
}

$current_page = 'reports';

$reportScopeOptions = [
    'reports_counts' => [
        'title' => 'Inventory Count Reports Only',
        'desc' => 'Archive saved Final Confirmation report snapshots.',
    ],
    'reports_dispensing' => [
        'title' => 'Dispensing Log Only',
        'desc' => 'Archive dispensing history used by the Monthly Inventory Report tab.',
    ],
    'reports' => [
        'title' => 'All Reports Data',
        'desc' => 'Archive saved reports and dispensing log history together.',
    ],
];

$archiveScopeLabels = [
    'inventory_counts' => 'Inventory Counts Only',
    'reports' => 'Reports & Exports Only',
    'reports_counts' => 'Inventory Count Reports Only',
    'reports_dispensing' => 'Dispensing Log Only',
    'medicine_batches' => 'Medicine Batches Only',
    'all' => 'All Pharmacy Data',
];

$archiveRuns = [];
if ($tab === 'archive') {
    $result = $conn->query(
        "SELECT ar.run_id, ar.scope, ar.archived_at, ar.summary, ar.restored_at,
                CONCAT(u1.first_name, ' ', u1.last_name) AS archived_by_name,
                CONCAT(u2.first_name, ' ', u2.last_name) AS restored_by_name
         FROM archive_runs ar
         JOIN users u1 ON u1.user_id = ar.archived_by
         LEFT JOIN users u2 ON u2.user_id = ar.restored_by
         ORDER BY ar.archived_at DESC, ar.run_id DESC"
    );
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $archiveRuns[] = $row;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - GabayMed</title>
    <link rel="stylesheet" href="../assets/css/doctor-dashboard.css">
    <link rel="stylesheet" href="../assets/css/pharmacist-dashboard.css">
    <link rel="stylesheet" href="../assets/css/pharmacist-backup-restore.css">
    <style>
        .rp-tabs {
            display: flex;
            gap: 4px;
            margin-bottom: 20px;
            border-bottom: 1px solid var(--border);
        }

        .rp-tab {
            padding: 10px 4px;
            margin-right: 22px;
            margin-bottom: -1px;
            border: none;
            background: none;
            font: inherit;
            font-weight: 700;
            font-size: 14px;
            color: var(--text-secondary);
            cursor: pointer;
            border-bottom: 2px solid transparent;
            text-decoration: none;
            display: inline-block;
        }

        .rp-tab.is-active {
            color: var(--teal);
            border-bottom-color: var(--teal);
        }

        .or-stat-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 14px;
            margin-bottom: 18px;
        }

        .or-stat-card {
            padding: 16px 18px;
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            background: var(--surface);
        }

        .or-stat-card .or-stat-label {
            font-size: 12px;
            font-weight: 700;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        .or-stat-card .or-stat-value {
            margin-top: 6px;
            font-size: 22px;
            font-weight: 700;
            color: var(--text-primary);
        }

        .or-stat-card .or-stat-note {
            margin-top: 4px;
            font-size: 11.5px;
            color: var(--text-muted);
        }

        .or-empty {
            padding: 16px;
            color: var(--text-secondary);
            font-size: 13px;
        }

        .rp-rank-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 22px;
            height: 22px;
            border-radius: 50%;
            background: var(--teal-light, #e6f7f5);
            color: var(--teal);
            font-size: 12px;
            font-weight: 700;
            margin-right: 8px;
        }

        .rp-month-form select {
            width: 100%;
            padding: 9px 10px;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            font: inherit;
            background: var(--surface);
        }

        .rp-compare-form {
            display: grid;
            gap: 12px;
        }

        .rp-compare-row {
            display: grid;
            grid-template-columns: 1fr auto 1fr;
            align-items: end;
            gap: 10px;
        }

        .rp-compare-field label {
            display: block;
            margin-bottom: 6px;
            font-size: 12px;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        .rp-compare-vs {
            padding-bottom: 10px;
            color: var(--text-muted);
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .rp-inline-alert {
            margin: 0 20px 16px;
            padding: 12px 14px;
            border: 1px solid #f3d9a8;
            border-radius: var(--radius-sm);
            background: var(--amber-light);
            color: #9a6c14;
            font-size: 13px;
            font-weight: 600;
        }

        .rp-compare-table {
            min-width: 980px;
        }

        .rp-compare-table thead th {
            text-align: center;
        }

        .rp-compare-table thead th:first-child,
        .rp-compare-table tbody td:first-child {
            text-align: left;
        }

        .rp-compare-table .rp-month-group {
            background: var(--teal-light, #e6f7f5);
            color: var(--teal);
        }

        .rp-current-note {
            display: block;
            margin-top: 2px;
            color: var(--text-muted);
            font-size: 11px;
            font-weight: 600;
            text-transform: none;
            letter-spacing: 0;
        }

        .rp-pad {
            padding: 0 20px;
        }

        .rp-mr-controls {
            display: flex;
            flex-wrap: wrap;
            align-items: flex-end;
            gap: 14px;
            padding: 0 20px 16px;
        }

        .rp-mr-controls .rp-compare-field {
            min-width: 210px;
        }

        .rp-mr-controls select,
        .rp-mr-toolbar select {
            padding: 9px 10px;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            font: inherit;
            background: var(--surface);
        }

        .rp-export-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .rp-two-col {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 18px;
            margin: 18px 0;
        }

        .rp-mr-toolbar {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 10px;
            padding: 0 20px 12px;
        }

        .rp-mr-search {
            display: flex;
            gap: 6px;
            flex: 1 1 220px;
        }

        .rp-mr-search input {
            flex: 1;
            min-width: 0;
            padding: 9px 10px;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            font: inherit;
            background: var(--surface);
        }

        .rp-chips {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            width: 100%;
        }

        .rp-chip {
            padding: 5px 12px;
            border: 1px solid var(--border);
            border-radius: 999px;
            background: var(--surface);
            color: var(--text-secondary);
            font-size: 12.5px;
            font-weight: 600;
            text-decoration: none;
        }

        .rp-chip.is-active {
            background: var(--teal-light, #e6fbf8);
            border-color: var(--teal);
            color: var(--teal-dark);
        }

        .rp-chip-count {
            margin-left: 5px;
            color: var(--text-muted);
        }

        .rp-num {
            text-align: right;
        }

        .rp-unit {
            display: block;
            font-size: 11.5px;
            font-weight: 400;
            color: var(--text-muted);
        }

        .rp-pill-none {
            background: #eef1f4;
            color: var(--text-secondary);
        }

        .rp-pager {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            padding: 12px 20px 16px;
            font-size: 13px;
            color: var(--text-secondary);
        }

        .rp-pager-links {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .rp-pager .is-disabled {
            opacity: 0.45;
            pointer-events: none;
        }

        .rp-history-link {
            color: inherit;
            font-weight: 700;
            text-decoration: none;
        }

        .rp-history-link:hover {
            color: var(--teal);
        }

        @media (max-width: 700px) {
            .rp-compare-row {
                grid-template-columns: 1fr;
            }

            .rp-compare-vs {
                padding: 0;
            }
        }
    </style>
</head>

<body>
    <div class="app-shell">
        <?php include 'includes/sidebar.php'; ?>
        <main class="main-content">
            <header class="page-header">
                <div>
                    <h1>Reports</h1>
                    <p class="page-subtitle">Saved inventory counts, dispensing activity, and archived data.</p>
                </div>
            </header>

            <nav class="rp-tabs">
                <a class="rp-tab <?php echo $tab === 'inventory' ? 'is-active' : ''; ?>" href="reports.php?tab=inventory">Inventory Counts</a>
                <a class="rp-tab <?php echo $tab === 'monthly' ? 'is-active' : ''; ?>" href="reports.php?tab=monthly">Monthly Inventory Report</a>
                <a class="rp-tab <?php echo $tab === 'backup' ? 'is-active' : ''; ?>" href="reports.php?tab=backup">Backup &amp; Archive</a>
                <a class="rp-tab <?php echo $tab === 'archive' ? 'is-active' : ''; ?>" href="reports.php?tab=archive">Archive</a>
            </nav>

            <?php if ($tab === 'inventory'): ?>
                <section class="card">
                    <div class="card-header">
                        <div>
                            <h2>Saved Reports</h2>
                            <span class="card-subtitle"><?php echo count($reports); ?> report<?php echo count($reports) === 1 ? '' : 's'; ?></span>
                        </div>
                    </div>
                    <?php if (empty($reports)): ?>
                        <div class="empty-state">
                            <p>No saved reports yet. Reports are created when you confirm an inventory count batch.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table class="data-table reports-table">
                                <thead>
                                    <tr>
                                        <th>Report Name</th>
                                        <th>Date Saved</th>
                                        <th>Saved By</th>
                                        <th>Total Items</th>
                                        <th>Total Count</th>
                                        <th>Download</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($reports as $report): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($report['report_name']); ?></strong></td>
                                            <td><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($report['saved_at']))); ?></td>
                                            <td><?php echo htmlspecialchars($report['saved_by']); ?></td>
                                            <td><?php echo (int) $report['total_items']; ?></td>
                                            <td><?php echo (int) $report['total_count']; ?></td>
                                            <td>
                                                <div class="report-download-actions">
                                                    <?php foreach (['excel' => 'Excel', 'pdf' => 'PDF', 'word' => 'Word'] as $format => $label): ?>
                                                        <a class="btn btn-secondary btn-sm" href="report-export.php?report_id=<?php echo (int) $report['report_id']; ?>&amp;format=<?php echo $format; ?>"><?php echo $label; ?></a>
                                                    <?php endforeach; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </section>

            <?php elseif ($tab === 'monthly'): ?>
                <?php
                $mrE = static function ($value) {
                    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
                };
                $mrSnap = $mrData['snapshot'];
                $mrIsCurrent = $mrSnap['is_current'];
                $mrFilters = [
                    'all' => 'All',
                    'activity' => 'With activity',
                    'low' => 'Below minimum',
                    'out' => 'Out of stock',
                    'none' => 'No movement',
                ];
                $mrSorts = [
                    'name' => 'Name (A-Z)',
                    'dispensed' => 'Most dispensed',
                    'closing' => 'Lowest closing stock',
                ];
                $mrLink = static function (array $override) use ($mrParams) {
                    return htmlspecialchars('reports.php?tab=monthly&' . monthlyReportQuery($mrParams, $override), ENT_QUOTES, 'UTF-8');
                };
                $mrExportQuery = htmlspecialchars(monthlyReportQuery($mrParams, ['page' => 1]), ENT_QUOTES, 'UTF-8');
                $mrStats = [
                    [
                        'label' => 'Total Received',
                        'value' => $mrSnap['summary']['received'],
                        'note' => 'Units received this month',
                    ],
                    [
                        'label' => 'Total Dispensed',
                        'value' => $mrSnap['summary']['dispensed'],
                        'note' => 'Units dispensed this month',
                    ],
                    [
                        'label' => 'Below Minimum',
                        'value' => $mrSnap['summary']['below_minimum'],
                        'note' => 'Stock under the minimum level',
                    ],
                    [
                        'label' => 'Out of Stock',
                        'value' => $mrSnap['summary']['out_of_stock_end'],
                        'note' => 'Zero stock ' . ($mrIsCurrent ? 'today' : 'at month end'),
                    ],
                ];
                ?>
                <section class="card">
                    <div class="card-header">
                        <div>
                            <h2>Monthly Inventory Report</h2>
                            <span class="card-subtitle">
                                <?php echo $mrE($reportMonthLabel); ?><?php echo $mrIsCurrent ? ' (in progress, values as of today)' : ''; ?>
                            </span>
                        </div>
                        <div class="rp-export-actions">
                            <a class="btn btn-secondary btn-sm" href="monthly-report-export.php?<?php echo $mrExportQuery; ?>&amp;format=excel" title="Exports every medicine matching the current search and filter">Excel</a>
                            <a class="btn btn-secondary btn-sm" href="monthly-report-export.php?<?php echo $mrExportQuery; ?>&amp;format=print" target="_blank" rel="noopener" title="Opens a print-ready page. Choose Save as PDF in the print dialog.">Print / PDF</a>
                        </div>
                    </div>

                    <form method="GET" action="reports.php" class="rp-mr-controls">
                        <input type="hidden" name="tab" value="monthly">
                        <?php if ($mrParams['filter'] !== 'all'): ?><input type="hidden" name="filter" value="<?php echo $mrE($mrParams['filter']); ?>"><?php endif; ?>
                        <?php if ($mrParams['sort'] !== 'name'): ?><input type="hidden" name="sort" value="<?php echo $mrE($mrParams['sort']); ?>"><?php endif; ?>
                        <?php if ($mrParams['q'] !== ''): ?><input type="hidden" name="q" value="<?php echo $mrE($mrParams['q']); ?>"><?php endif; ?>
                        <div class="rp-compare-field">
                            <label for="mrMonth">Report month</label>
                            <select id="mrMonth" name="month" onchange="this.form.submit()">
                                <?php foreach ($monthOptions as $opt): ?>
                                    <option value="<?php echo $mrE($opt['month_key']); ?>" <?php echo $opt['month_key'] === $reportMonthKey ? 'selected' : ''; ?>>
                                        <?php echo $mrE($opt['month']); ?><?php echo $opt['month_key'] === $currentMonthKey ? ' (in progress)' : ''; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <noscript><button type="submit" class="btn btn-secondary btn-sm">Apply</button></noscript>
                    </form>

                    <div class="or-stat-grid rp-pad">
                        <?php foreach ($mrStats as $stat): ?>
                            <div class="or-stat-card">
                                <div class="or-stat-label"><?php echo $mrE($stat['label']); ?></div>
                                <div class="or-stat-value"><?php echo number_format($stat['value']); ?></div>
                                <div class="or-stat-note"><?php echo $mrE($stat['note']); ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>

                <div class="rp-two-col">
                    <section class="card">
                        <div class="card-header">
                            <div>
                                <h2>Fast-Moving Medicines</h2>
                                <span class="card-subtitle">Top 5 by units dispensed in <?php echo $mrE($reportMonthLabel); ?></span>
                            </div>
                        </div>
                        <?php if (empty($fastMovers)): ?>
                            <div class="or-empty">No dispensing recorded for this month.</div>
                        <?php else: ?>
                            <div class="table-wrap">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Medicine</th>
                                            <th class="rp-num">Units</th>
                                            <th class="rp-num">Share</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($fastMovers as $i => $mover): ?>
                                            <tr>
                                                <td><span class="rp-rank-badge"><?php echo $i + 1; ?></span><strong><?php echo $mrE($mover['name']); ?></strong></td>
                                                <td class="rp-num"><?php echo number_format($mover['units']); ?></td>
                                                <td class="rp-num"><?php echo $selectedDispenseStats['units'] > 0 ? number_format(($mover['units'] / $selectedDispenseStats['units']) * 100, 1) : '0.0'; ?>%</td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </section>

                    <section class="card">
                        <div class="card-header">
                            <div>
                                <h2>Recent Months</h2>
                                <span class="card-subtitle">Select a month to make it the report month</span>
                            </div>
                        </div>
                        <div class="table-wrap">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Month</th>
                                        <th class="rp-num">Units</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($dispenseMonthlyHistory as $report): ?>
                                        <tr>
                                            <td>
                                                <a class="rp-history-link" href="<?php echo $mrLink(['month' => $report['month_key'], 'page' => 1]); ?>"><?php echo $mrE($report['month']); ?></a>
                                                <span class="rp-unit"><?php echo number_format($report['transactions']); ?> transactions<?php echo $report['top_medicine'] ? ' · Top: ' . $mrE($report['top_medicine']) : ''; ?></span>
                                            </td>
                                            <td class="rp-num"><?php echo number_format($report['units']); ?></td>
                                            <td>
                                                <span class="status-pill <?php echo $report['status'] === 'Finalized' ? 'status-checked-in' : 'status-waiting'; ?>">
                                                    <?php echo $mrE($report['status']); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>
                </div>

                <section class="card">
                    <div class="card-header">
                        <div>
                            <h2>Medicine Breakdown</h2>
                            <span class="card-subtitle">
                                <?php echo number_format($mrSnap['summary']['activity_medicines']); ?> of <?php echo number_format($mrData['counts']['all']); ?> medicines had activity.
                                Opening and closing stock are reconstructed from current stock, receipts, and dispenses.
                            </span>
                        </div>
                    </div>

                    <form method="GET" action="reports.php" class="rp-mr-toolbar">
                        <input type="hidden" name="tab" value="monthly">
                        <input type="hidden" name="month" value="<?php echo $mrE($reportMonthKey); ?>">
                        <?php if ($mrParams['filter'] !== 'all'): ?><input type="hidden" name="filter" value="<?php echo $mrE($mrParams['filter']); ?>"><?php endif; ?>
                        <div class="rp-mr-search">
                            <input type="search" name="q" value="<?php echo $mrE($mrParams['q']); ?>" placeholder="Search medicine" aria-label="Search medicine">
                            <button type="submit" class="btn btn-secondary btn-sm">Search</button>
                        </div>
                        <select name="sort" aria-label="Sort medicines" onchange="this.form.submit()">
                            <?php foreach ($mrSorts as $key => $label): ?>
                                <option value="<?php echo $mrE($key); ?>" <?php echo $mrParams['sort'] === $key ? 'selected' : ''; ?>><?php echo $mrE($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="rp-chips">
                            <?php foreach ($mrFilters as $key => $label): ?>
                                <a class="rp-chip <?php echo $mrParams['filter'] === $key ? 'is-active' : ''; ?>" href="<?php echo $mrLink(['filter' => $key, 'page' => 1]); ?>">
                                    <?php echo $mrE($label); ?><span class="rp-chip-count"><?php echo number_format($mrData['counts'][$key]); ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </form>

                    <?php if ($mrData['counts']['all'] === 0): ?>
                        <div class="or-empty">No stock activity or on-hand inventory found for this month.</div>
                    <?php elseif ($mrPage['total'] === 0): ?>
                        <div class="or-empty">No medicines match these filters. <a href="<?php echo $mrLink(['filter' => 'all', 'q' => '', 'page' => 1]); ?>">Clear filters</a></div>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table class="data-table rp-mr-table">
                                <thead>
                                    <tr>
                                        <th>Medicine</th>
                                        <th class="rp-num">Opening</th>
                                        <th class="rp-num">Received</th>
                                        <th class="rp-num">Dispensed</th>
                                        <th class="rp-num"><?php echo $mrIsCurrent ? 'Current' : 'Closing'; ?></th>
                                        <th class="rp-num">Min</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($mrPage['rows'] as $row): ?>
                                        <?php [$statusLabel, $statusClass] = monthlyReportStatusMeta($row['status']); ?>
                                        <tr>
                                            <td><strong><?php echo $mrE($row['name']); ?></strong><span class="rp-unit"><?php echo $mrE($row['unit']); ?></span></td>
                                            <td class="rp-num"><?php echo number_format($row['stock_start']); ?></td>
                                            <td class="rp-num"><?php echo number_format($row['received']); ?></td>
                                            <td class="rp-num"><?php echo number_format($row['dispensed']); ?></td>
                                            <td class="rp-num"><strong><?php echo number_format($row['stock_end']); ?></strong></td>
                                            <td class="rp-num"><?php echo number_format($row['minimum']); ?></td>
                                            <td><span class="status-pill <?php echo $mrE($statusClass); ?>"><?php echo $mrE($statusLabel); ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="rp-pager">
                            <span>Showing <?php echo number_format($mrPage['from']); ?>-<?php echo number_format($mrPage['to']); ?> of <?php echo number_format($mrPage['total']); ?> medicines</span>
                            <span class="rp-pager-links">
                                <a class="btn btn-secondary btn-sm <?php echo $mrPage['page'] <= 1 ? 'is-disabled' : ''; ?>" href="<?php echo $mrLink(['page' => $mrPage['page'] - 1]); ?>">Prev</a>
                                <span>Page <?php echo $mrPage['page']; ?> of <?php echo $mrPage['pages']; ?></span>
                                <a class="btn btn-secondary btn-sm <?php echo $mrPage['page'] >= $mrPage['pages'] ? 'is-disabled' : ''; ?>" href="<?php echo $mrLink(['page' => $mrPage['page'] + 1]); ?>">Next</a>
                            </span>
                        </div>
                    <?php endif; ?>
                </section>


            <?php elseif ($tab === 'backup'): ?>
                <section class="card">
                    <div class="card-header">
                        <div>
                            <h2>Backup &amp; Archive</h2>
                            <span class="card-subtitle">Manage backups of this page's data, and archive it when needed.</span>
                        </div>
                    </div>
                    <div class="br-backup-list">
                        <?php foreach ($reportScopeOptions as $key => $opt): ?>
                            <a class="btn btn-secondary btn-sm" href="backup-export.php?scope=<?php echo urlencode($key); ?>">
                                Download <?php echo htmlspecialchars($opt['title']); ?>
                            </a>
                        <?php endforeach; ?>
                    </div>

                    <div class="br-warning">
                        <svg class="br-warning-icon" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"></path>
                            <line x1="12" y1="9" x2="12" y2="13"></line>
                            <line x1="12" y1="17" x2="12.01" y2="17"></line>
                        </svg>
                        <div class="br-warning-text">
                            <strong>Please choose the data you want to archive.</strong>
                            <p>Archived data is hidden from normal views but not deleted — download a backup too if you'd like an offline copy, and restore anytime from the Archive tab. The medicine catalog and current stock levels are never affected.</p>
                        </div>
                    </div>

                    <form id="reportsResetForm">
                        <?= csrf_field() ?>
                        <div class="br-scope-grid" id="reportsScopeGrid">
                            <?php foreach ($reportScopeOptions as $key => $opt): ?>
                                <label class="br-scope-card" data-scope="<?php echo htmlspecialchars($key); ?>">
                                    <input type="radio" name="scope" value="<?php echo htmlspecialchars($key); ?>">
                                    <span class="br-scope-icon">
                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <polyline points="21 8 21 21 3 21 3 8"></polyline>
                                            <rect x="1" y="3" width="22" height="5"></rect>
                                            <line x1="10" y1="12" x2="14" y2="12"></line>
                                        </svg>
                                    </span>
                                    <div class="br-scope-title"><?php echo htmlspecialchars($opt['title']); ?></div>
                                    <div class="br-scope-desc"><?php echo htmlspecialchars($opt['desc']); ?></div>
                                </label>
                            <?php endforeach; ?>
                        </div>

                        <div class="form-group br-password-row">
                            <label class="form-label" for="reportsResetPassword">Your Password</label>
                            <input class="form-input" type="password" id="reportsResetPassword" name="password" placeholder="Enter your password" autocomplete="current-password">
                        </div>
                        <div class="br-actions">
                            <button class="btn btn-danger" type="submit" id="reportsResetSubmitBtn" disabled>Archive Selected Data</button>
                            <span class="br-inline-error" id="reportsResetError" hidden></span>
                        </div>
                    </form>

                    <div class="br-reminder">
                        <span>Archiving is reversible — restore any run anytime from the Archive tab.</span>
                    </div>
                </section>

            <?php else: ?>
                <section class="card">
                    <div class="card-header">
                        <div>
                            <h2>Archive</h2>
                            <span class="card-subtitle"><?php echo count($archiveRuns); ?> run(s)</span>
                        </div>
                    </div>
                    <?php if (empty($archiveRuns)): ?>
                        <div class="empty-state">
                            <p>Nothing archived yet. Use the Backup &amp; Archive tab to archive data — it'll show up here, restorable anytime.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Scope</th>
                                        <th>Archived</th>
                                        <th>Contains</th>
                                        <th>Status</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($archiveRuns as $run): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($archiveScopeLabels[$run['scope']] ?? $run['scope']); ?></strong></td>
                                            <td>
                                                <?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($run['archived_at']))); ?>
                                                <div style="font-size:12px;color:var(--text-muted);margin-top:2px;">by <?php echo htmlspecialchars($run['archived_by_name']); ?></div>
                                            </td>
                                            <td><?php echo htmlspecialchars($run['summary'] ?: '—'); ?></td>
                                            <td>
                                                <?php if ($run['restored_at']): ?>
                                                    <span class="status-pill status-checked-in">Restored</span>
                                                    <div style="font-size:12px;color:var(--text-muted);margin-top:2px;"><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($run['restored_at']))); ?> by <?php echo htmlspecialchars($run['restored_by_name']); ?></div>
                                                <?php else: ?>
                                                    <span class="status-pill status-waiting">Archived</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (!$run['restored_at']): ?>
                                                    <button class="btn btn-secondary btn-sm archive-restore-btn" type="button" data-run-id="<?php echo (int) $run['run_id']; ?>">Restore</button>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <footer class="page-footer">
                <p>&copy; <?php echo date('Y'); ?> GabayMed Hospital Management System</p>
            </footer>
        </main>
    </div>

    <div class="modal-backdrop" id="reportsResetModal">
        <div class="modal-box br-modal-box">
            <h3>Confirm archiving</h3>
            <p id="reportsResetModalText">This will move the selected data to the Archive. It will no longer appear in normal views, but can be restored anytime.</p>
            <div class="br-modal-actions">
                <button class="btn btn-secondary" type="button" id="reportsResetCancelBtn">Cancel</button>
                <button class="btn btn-danger" type="button" id="reportsResetConfirmBtn">Yes, Archive Data</button>
            </div>
        </div>
    </div>

    <script>
        (function() {
            const form = document.getElementById('reportsResetForm');
            if (!form) return; // only present on the Backup & Archive tab

            const scopeCards = Array.from(document.querySelectorAll('#reportsScopeGrid .br-scope-card'));
            const passwordInput = document.getElementById('reportsResetPassword');
            const submitBtn = document.getElementById('reportsResetSubmitBtn');
            const errorBox = document.getElementById('reportsResetError');
            const modal = document.getElementById('reportsResetModal');
            const modalText = document.getElementById('reportsResetModalText');
            const cancelBtn = document.getElementById('reportsResetCancelBtn');
            const confirmBtn = document.getElementById('reportsResetConfirmBtn');

            const scopeTitles = <?php
                                $titles = [];
                                foreach ($reportScopeOptions as $key => $opt) $titles[$key] = $opt['title'];
                                echo json_encode($titles);
                                ?>;

            function selectedScope() {
                const checked = form.querySelector('input[name="scope"]:checked');
                return checked ? checked.value : '';
            }

            function syncState() {
                scopeCards.forEach(function(card) {
                    const input = card.querySelector('input[type="radio"]');
                    card.classList.toggle('is-selected', !!input && input.checked);
                });
                submitBtn.disabled = !selectedScope() || passwordInput.value.trim() === '';
            }

            scopeCards.forEach(function(card) {
                card.addEventListener('click', function() {
                    const input = card.querySelector('input[type="radio"]');
                    input.checked = true;
                    syncState();
                });
            });
            passwordInput.addEventListener('input', syncState);
            syncState();

            function openModal() {
                const scope = selectedScope();
                modalText.textContent = 'This will move to the Archive: ' + (scopeTitles[scope] || 'the selected data') + '. It will no longer appear in normal views, but can be restored anytime from the Archive tab.';
                document.body.classList.add('modal-open');
                modal.classList.add('active');
            }

            function closeModal() {
                document.body.classList.remove('modal-open');
                modal.classList.remove('active');
            }

            form.addEventListener('submit', function(event) {
                event.preventDefault();
                errorBox.hidden = true;
                errorBox.textContent = '';
                openModal();
            });

            cancelBtn.addEventListener('click', closeModal);
            modal.addEventListener('click', function(event) {
                if (event.target === modal) closeModal();
            });

            confirmBtn.addEventListener('click', async function() {
                confirmBtn.disabled = true;
                const data = new FormData(form);
                data.append('action', 'reset_data');

                try {
                    const response = await fetch('backup-restore-actions.php', {
                        method: 'POST',
                        body: data,
                    });
                    const result = await response.json();
                    if (!response.ok || !result.success) {
                        throw new Error(result.error || 'Could not archive data.');
                    }
                    closeModal();
                    window.location.reload();
                } catch (err) {
                    closeModal();
                    errorBox.textContent = err.message || 'Could not archive data.';
                    errorBox.hidden = false;
                    confirmBtn.disabled = false;
                }
            });
        })();

        (function() {
            const restoreBtns = Array.from(document.querySelectorAll('.archive-restore-btn'));
            if (!restoreBtns.length) return; // only present on the Archive tab

            const csrfToken = <?php echo json_encode(csrf_token()); ?>;

            restoreBtns.forEach(function(btn) {
                btn.addEventListener('click', async function() {
                    if (!confirm('Restore this archived run? Everything in it will reappear in normal views.')) {
                        return;
                    }
                    btn.disabled = true;
                    btn.textContent = 'Restoring…';

                    const data = new FormData();
                    data.append('action', 'restore_archive');
                    data.append('run_id', btn.dataset.runId);
                    data.append('csrf_token', csrfToken);

                    try {
                        const response = await fetch('backup-restore-actions.php', {
                            method: 'POST',
                            body: data,
                        });
                        const result = await response.json();
                        if (!response.ok || !result.success) {
                            throw new Error(result.error || 'Could not restore this archive.');
                        }
                        window.location.reload();
                    } catch (err) {
                        alert(err.message || 'Could not restore this archive.');
                        btn.disabled = false;
                        btn.textContent = 'Restore';
                    }
                });
            });
        })();
    </script>
</body>

</html>