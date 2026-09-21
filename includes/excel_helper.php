<?php
// includes/excel_helper.php
//
// "Excel" support for the Purchase Request / Purchase Order / Receiving
// workflow, using plain CSV (opens fine in Excel/Sheets, no external
// library or Composer dependency needed — matches the rest of this
// project's native-PHP-only approach).
//
// Two responsibilities:
//   1. Stream a CSV template/export to the browser (csv_download).
//   2. Read an uploaded CSV back into an array of associative rows,
//      keyed by a normalized (lowercased, trimmed) version of the
//      header row (csv_parse_upload).

/**
 * Sends $rows as a downloadable CSV and exits. $header is the first row
 * (column labels shown to the user); each entry in $rows must be a plain
 * list of values in the same order as $header.
 */
function csv_download(string $filename, array $header, array $rows): void
{
    // Same reasoning as respond() in the *-actions.php files: discard any
    // buffered output (stray warnings/notices) so the download is a clean
    // CSV file, never one with error text prepended to it.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    // UTF-8 BOM so Excel on Windows doesn't mangle non-ASCII characters.
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $header);
    foreach ($rows as $row) {
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

/**
 * Validates and reads an uploaded CSV file ($_FILES[$fieldName]).
 * Returns a list of associative rows keyed by normalized header names
 * (lowercase, trimmed, spaces collapsed to underscores) — e.g. a column
 * labeled "Quantity Requested" becomes key "quantity_requested".
 *
 * Throws RuntimeException with a user-facing message on any problem
 * (missing file, wrong type, empty, unreadable).
 */
function csv_parse_upload(string $fieldName, int $maxRows = 500): array
{
    if (!isset($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] === UPLOAD_ERR_NO_FILE) {
        throw new RuntimeException('Please choose a CSV file to upload.');
    }
    $file = $_FILES[$fieldName];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('The file could not be uploaded. Please try again.');
    }
    if ($file['size'] > 2 * 1024 * 1024) {
        throw new RuntimeException('The file is too large (2MB max).');
    }
    $originalName = (string) $file['name'];
    $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($extension, ['csv', 'txt'], true)) {
        throw new RuntimeException('Please upload a .csv file (export it as CSV from Excel/Google Sheets).');
    }

    $handle = fopen($file['tmp_name'], 'r');
    if ($handle === false) {
        throw new RuntimeException('The file could not be read. Please try again.');
    }

    // Strip a UTF-8 BOM if present so the first header cell parses cleanly.
    $bom = fread($handle, 3);
    if ($bom !== "\xEF\xBB\xBF") {
        rewind($handle);
    }

    $headerRow = fgetcsv($handle);
    if ($headerRow === false || count($headerRow) === 0) {
        fclose($handle);
        throw new RuntimeException('The file appears to be empty.');
    }

    $keys = array_map('normalize_csv_header', $headerRow);

    $rows = [];
    while (($line = fgetcsv($handle)) !== false) {
        // Skip fully blank rows (trailing blank lines are common in exports).
        if (count($line) === 1 && trim((string) $line[0]) === '') {
            continue;
        }
        $row = [];
        foreach ($keys as $index => $key) {
            $row[$key] = isset($line[$index]) ? trim((string) $line[$index]) : '';
        }
        $rows[] = $row;
        if (count($rows) > $maxRows) {
            fclose($handle);
            throw new RuntimeException("This file has more than {$maxRows} rows. Please split it into smaller batches.");
        }
    }
    fclose($handle);

    if (empty($rows)) {
        throw new RuntimeException('The file has a header row but no data rows.');
    }

    return $rows;
}

function normalize_csv_header(string $label): string
{
    $key = strtolower(trim($label));
    $key = preg_replace('/[^a-z0-9]+/', '_', $key);
    return trim($key, '_');
}
