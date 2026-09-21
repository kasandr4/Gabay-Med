<?php
// pharmacist/monthly-report-export.php
// Exports the Monthly Inventory Report tab from reports.php.
//   format=excel : HTML table served as .xls (same approach as report-export.php)
//   format=print : print-ready page; choose "Save as PDF" in the print dialog
// Uses the same GET params as the tab (month, q, filter, sort) and exports
// every medicine that matches them, not just the current page.
require_once '../includes/auth_guard.php';
require_role('pharmacist');
require_once '../config/db.php';
require_once '../includes/audit_log.php';
require_once 'includes/monthly_report_helpers.php';

$format = strtolower(trim($_GET['format'] ?? ''));
if (!in_array($format, ['excel', 'print'], true)) {
    http_response_code(400);
    exit('Invalid report request.');
}

$pharmacistId = (int) $_SESSION['user_id'];
$currentMonthKey = date('Y-m');
$params = monthlyReportParams($_GET, $currentMonthKey);
$data = monthlyReportBuild($conn, $params, $currentMonthKey);

$snapshot = $data['snapshot'];
$isCurrent = $snapshot['is_current'];
$monthLabel = date('F Y', strtotime($params['month'] . '-01'));

$escape = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

$filterLabels = [
    'all' => 'All medicines',
    'activity' => 'With activity',
    'low' => 'Below minimum',
    'out' => 'Out of stock',
    'none' => 'No movement',
];
$sortLabels = [
    'name' => 'Name (A-Z)',
    'dispensed' => 'Most dispensed',
    'closing' => 'Lowest closing stock',
];

$headers = ['Medicine', 'Unit', 'Opening', 'Received', 'Dispensed', $isCurrent ? 'Current' : 'Closing', 'Minimum'];
$headers[] = 'Status';

$rows = [];
foreach ($data['rows'] as $row) {
    $line = [
        $row['name'],
        $row['unit'],
        (string) $row['stock_start'],
        (string) $row['received'],
        (string) $row['dispensed'],
        (string) $row['stock_end'],
        (string) $row['minimum'],
    ];
    $line[] = monthlyReportStatusMeta($row['status'])[0];
    $rows[] = $line;
}

$title = 'Monthly Inventory Report - ' . $monthLabel;
$summary = $snapshot['summary'];
$summaryLine = 'Received: ' . number_format($summary['received'])
    . ' | Dispensed: ' . number_format($summary['dispensed'])
    . ' | Below minimum: ' . number_format($summary['below_minimum'])
    . ' | Out of stock: ' . number_format($summary['out_of_stock_end']);

$notes = [];
if ($isCurrent) {
    $notes[] = $monthLabel . ' is in progress. Values are as of ' . date('M j, Y g:i A') . '.';
}
$appliedFilters = [];
if ($params['q'] !== '') {
    $appliedFilters[] = 'search "' . $params['q'] . '"';
}
if ($params['filter'] !== 'all') {
    $appliedFilters[] = 'filter: ' . $filterLabels[$params['filter']];
}
$appliedFilters[] = 'sorted by ' . $sortLabels[$params['sort']];
$notes[] = 'Showing ' . count($rows) . ' of ' . $data['counts']['all'] . ' medicines (' . implode(', ', $appliedFilters) . ').';
$notes[] = 'Opening and closing stock are reconstructed from current stock, receipts, and dispenses.';

$baseName = 'monthly-inventory-report-' . $params['month'];

write_audit_log(
    $conn,
    $pharmacistId,
    'pharmacist',
    'report_exported',
    'reports',
    "Exported Monthly Inventory Report for {$monthLabel} as {$format}."
);

function monthlyReportExportHtml(string $title, string $summaryLine, array $notes, array $headers, array $rows, callable $escape, bool $forPrint): string
{
    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>' . $escape($title) . '</title><style>'
        . 'body{font-family:Arial,sans-serif;color:#1c2733;padding:24px}h1{font-size:20px;color:#0f9c8d;margin:0 0 6px}'
        . 'p{font-size:12px;color:#6b7785;margin:2px 0}table{width:100%;border-collapse:collapse;font-size:11px;margin-top:14px}'
        . 'th,td{text-align:left;padding:6px 7px;border:1px solid #dfe5ea}th{background:#e6fbf8;color:#0f766e}'
        . '.num{text-align:right}.bar{margin-bottom:14px}.bar button{padding:8px 14px;font-size:13px;cursor:pointer}'
        . '@media print{.bar{display:none}body{padding:0}thead{display:table-header-group}tr{page-break-inside:avoid}}'
        . '</style></head><body>';
    if ($forPrint) {
        $html .= '<div class="bar"><button type="button" onclick="window.print()">Print / Save as PDF</button></div>';
    }
    $html .= '<h1>' . $escape($title) . '</h1><p><strong>' . $escape($summaryLine) . '</strong></p>';
    foreach ($notes as $note) {
        $html .= '<p>' . $escape($note) . '</p>';
    }
    $html .= '<table><thead><tr>';
    foreach ($headers as $header) {
        $html .= '<th>' . $escape($header) . '</th>';
    }
    $html .= '</tr></thead><tbody>';
    foreach ($rows as $row) {
        $html .= '<tr>';
        foreach ($row as $index => $cell) {
            $isNumber = $index >= 2 && $index < count($row) - 1;
            $html .= '<td' . ($isNumber ? ' class="num"' : '') . '>' . $escape($cell) . '</td>';
        }
        $html .= '</tr>';
    }
    $html .= '</tbody></table>';
    if ($forPrint) {
        $html .= '<script>window.addEventListener("load",function(){window.print();});</script>';
    }
    return $html . '</body></html>';
}

if ($format === 'excel') {
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $baseName . '.xls"');
    echo monthlyReportExportHtml($title, $summaryLine, $notes, $headers, $rows, $escape, false);
    exit;
}

header('Content-Type: text/html; charset=UTF-8');
echo monthlyReportExportHtml($title, $summaryLine, $notes, $headers, $rows, $escape, true);
