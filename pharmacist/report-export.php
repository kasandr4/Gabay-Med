<?php
// Server-side dependency-free export for immutable inventory count reports.
require_once '../includes/auth_guard.php';
require_role('pharmacist');
require_once '../config/db.php';
require_once '../includes/audit_log.php';

$reportId = filter_var($_GET['report_id'] ?? null, FILTER_VALIDATE_INT);
$format = strtolower(trim($_GET['format'] ?? ''));
if (!$reportId || !in_array($format, ['excel', 'pdf', 'word'], true)) {
    http_response_code(400);
    exit('Invalid report request.');
}

$stmt = $conn->prepare(
    "SELECT r.report_id, r.report_name, r.saved_at, r.total_items, r.total_count,
            r.snapshot_data, CONCAT(u.first_name, ' ', u.last_name) AS saved_by
     FROM inventory_count_reports r
     JOIN users u ON u.user_id = r.saved_by
     WHERE r.report_id = ? AND r.saved_by = ?"
);
$pharmacistId = (int) $_SESSION['user_id'];
$stmt->bind_param('ii', $reportId, $pharmacistId);
$stmt->execute();
$report = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$report) {
    http_response_code(404);
    exit('Report not found.');
}

$lines = json_decode($report['snapshot_data'], true);
if (!is_array($lines)) {
    http_response_code(500);
    exit('Report snapshot is invalid.');
}

$headers = ['Item #', 'Medicine', 'Unit', 'System Count', 'Staff Count', 'Final Count', 'Difference', 'Remarks'];
$rows = [];
foreach ($lines as $line) {
    $rows[] = [
        (string) ($line['item_number'] ?? ''),
        (string) ($line['medicine'] ?? ''),
        (string) ($line['unit'] ?? ''),
        (string) (int) ($line['system_count'] ?? 0),
        (string) (int) ($line['staff_count'] ?? 0),
        (string) (int) ($line['final_count'] ?? 0),
        (string) (int) ($line['difference'] ?? 0),
        (string) ($line['remarks'] ?? ''),
    ];
}

$baseName = preg_replace('/[^A-Za-z0-9_-]+/', '-', $report['report_name']) ?: 'inventory-count-report';
$escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
write_audit_log($conn, $pharmacistId, 'pharmacist', 'report_exported', 'reports', "Exported {$report['report_name']} as {$format}.");

function reportHtml(array $report, array $headers, array $rows, callable $escape): string
{
    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>'
        . 'body{font-family:Arial,sans-serif;color:#1c2733;padding:24px}h1{font-size:20px;color:#0f9c8d}'
        . 'p{font-size:12px;color:#6b7785}table{width:100%;border-collapse:collapse;font-size:11px}'
        . 'th,td{text-align:left;padding:7px;border:1px solid #dfe5ea}th{background:#e6fbf8;color:#0f766e}'
        . '</style></head><body><h1>' . $escape((string) $report['report_name']) . '</h1>'
        . '<p>Saved ' . $escape((string) $report['saved_at']) . ' by ' . $escape((string) $report['saved_by'])
        . ' | Total items: ' . (int) $report['total_items'] . ' | Total count: ' . (int) $report['total_count'] . '</p><table><thead><tr>';
    foreach ($headers as $header) $html .= '<th>' . $escape($header) . '</th>';
    $html .= '</tr></thead><tbody>';
    foreach ($rows as $row) {
        $html .= '<tr>';
        foreach ($row as $cell) $html .= '<td>' . $escape($cell) . '</td>';
        $html .= '</tr>';
    }
    return $html . '</tbody></table></body></html>';
}

if ($format === 'excel' || $format === 'word') {
    $extension = $format === 'excel' ? 'xls' : 'doc';
    $contentType = $format === 'excel' ? 'application/vnd.ms-excel' : 'application/msword';
    header('Content-Type: ' . $contentType . '; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $baseName . '.' . $extension . '"');
    echo reportHtml($report, $headers, $rows, $escape);
    exit;
}

function pdfEscape(string $value): string
{
    return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ' '], $value);
}

function buildPdf(string $title, array $headers, array $rows, array $report): string
{
    $lines = [$title, 'Saved ' . $report['saved_at'] . ' by ' . $report['saved_by'], ''];
    $lines[] = implode(' | ', $headers);
    foreach ($rows as $row) $lines[] = implode(' | ', $row);
    $content = "BT\n/F1 8 Tf\n40 800 Td\n";
    foreach ($lines as $index => $line) {
        if ($index > 0) $content .= "0 -12 Td\n";
        $content .= '(' . pdfEscape(substr($line, 0, 145)) . ") Tj\n";
    }
    $content .= "ET";
    $objects = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "\nendstream",
    ];
    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $number => $object) {
        $offsets[] = strlen($pdf);
        $pdf .= ($number + 1) . " 0 obj\n" . $object . "\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
    for ($i = 1; $i <= count($objects); $i++) $pdf .= sprintf('%010d 00000 n \n', $offsets[$i]);
    return $pdf . "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
}

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $baseName . '.pdf"');
echo buildPdf((string) $report['report_name'], $headers, $rows, $report);
