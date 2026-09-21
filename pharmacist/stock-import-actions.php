<?php
// pharmacist/stock-import-actions.php
//
// Backend for the drag-and-drop import on the "From Approved PO" tab of
// record-stock-batch.php.
//
// The pharmacist drops a medicine list (CSV or Excel) saying how many of
// each medicine to add. Each row is matched to a medicine that already
// exists in the catalog and, once the pharmacist has reviewed the preview
// and confirmed, becomes a NEW STOCK BATCH for that medicine (raising
// inventory_medicines.current_stock by the quantity in the file). That is
// exactly what Record Stock Batch does one medicine at a time, so this
// goes through the same credit_medicine_batch() helper - stock and batch
// history can never drift apart. There is no source to choose: everything
// imported here is Purchase Order stock (the tab is "From Approved PO").
//
// It ADDS to stock; it never overwrites a count. Fixing a wrong count is
// what Inventory Count / count adjustments are for.
//
// Two steps, so nothing changes until the pharmacist has seen the result:
//   parse_upload  reads the file and matches rows to the catalog. Changes
//                 nothing; returns a preview for the page to show.
//   confirm       the ONLY place stock changes: one transaction, one batch
//                 per confirmed row.
//
// Rows that can't be matched to exactly one catalog medicine are returned
// as skipped (with the reason) and can't be confirmed - a new medicine has
// to be added through Record Stock Batch > New Medicine first.
//
// File format (header row required; column names are case-insensitive):
//   Medicine ID          optional, the most reliable way to match
//   Medicine             the medicine's name (also accepts Name / Description)
//   Strength             optional, helps matching when the name has none
//   Qty to Add           how many to add (also accepts Quantity / Qty / Delivered)
//   Batch No             optional
//   Expiry Date          optional, YYYY-MM-DD (Excel dates are fine too)
//   Unit Price           optional
//   Selling Price        optional
// The downloadable template already has every medicine listed with its ID.
ob_start();
require_once '../includes/auth_guard.php';
require_role('pharmacist');
require_once '../config/db.php';
require_once '../includes/csrf.php';
require_once '../includes/audit_log.php';
require_once '../includes/excel_helper.php';
require_once '../includes/xlsx_helper.php';
require_once '../includes/inventory_helpers.php';

$pharmacistId = (int) $_SESSION['user_id'];

const STOCK_IMPORT_TEMPLATE_HEADER = ['Medicine ID', 'Medicine', 'Unit', 'Current Stock', 'Qty to Add', 'Batch No', 'Expiry Date (YYYY-MM-DD)', 'Unit Price', 'Selling Price'];
const STOCK_IMPORT_MAX_ROWS = 500;
const STOCK_IMPORT_MAX_QTY = 10000000;
const STOCK_IMPORT_MAX_PRICE = 99999999.99;
// Every batch created by this import is Purchase Order stock.
const STOCK_IMPORT_SOURCE = 'purchase_order';

// Column-name aliases, all already in csv_parse_upload()'s normalized form
// (lowercase, non-alphanumerics -> "_"). "Current Stock" is deliberately NOT
// an alias for the quantity: the template shows it for reference only.
const STOCK_IMPORT_ALIASES = [
    'id' => ['medicine_id', 'id'],
    'name' => ['medicine', 'medicine_name', 'name', 'generic_name', 'item_description', 'description', 'item'],
    'strength' => ['strength'],
    'qty' => ['qty_to_add', 'quantity_to_add', 'quantity', 'qty', 'qty_delivered', 'quantity_delivered', 'qty_received', 'quantity_received', 'delivered', 'units'],
    'batch' => ['batch_no', 'batch', 'batch_number'],
    'expiry' => ['expiry_date_yyyy_mm_dd', 'expiry_date', 'expiry', 'expiration_date', 'exp_date'],
    'unit_price' => ['unit_price'],
    'selling_price' => ['selling_price'],
];

function respond($data, $status = 200)
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/** Strict Y-m-d check: rejects things like 2026-02-31 that DateTime silently rolls over. */
function imp_valid_ymd(string $value): bool
{
    $date = DateTime::createFromFormat('Y-m-d', $value);
    if (!$date) {
        return false;
    }
    $parts = explode('-', $value);
    return count($parts) === 3 && checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0]);
}

/** Lowercase, collapse whitespace, trim stray punctuation - for name matching. */
function imp_norm(string $s): string
{
    $s = strtolower(trim($s));
    $s = preg_replace('/\s+/', ' ', $s);
    return trim($s, " \t,;.-");
}

/** Same, but ignoring bracketed dosage forms: "Lisinopril 10mg (Tablet)" ~ "Lisinopril 10mg". */
function imp_loose(string $s): string
{
    return imp_norm(preg_replace('/\([^()]*\)/', ' ', $s));
}

/**
 * Reads the first sheet of an uploaded .xlsx as a plain table: the first
 * row that has a recognizable name column is the header, everything under
 * it is data. Returns rows in the same normalized-key shape
 * csv_parse_upload() gives, plus the sheet row number for messages.
 *
 * @return array<int, array{_line:int, ...}>
 */
function imp_read_xlsx(string $fieldName): array
{
    $file = $_FILES[$fieldName];
    if ($file['size'] > 5 * 1024 * 1024) {
        throw new RuntimeException('The file is too large (5MB max).');
    }
    $cellsByRow = xlsx_read_first_sheet($file['tmp_name']);

    $nameAliases = STOCK_IMPORT_ALIASES['name'];
    $headerRow = null;
    $headers = [];
    foreach ($cellsByRow as $rowNum => $cells) {
        if ($rowNum > 30) {
            break;
        }
        $found = [];
        foreach ($cells as $col => $value) {
            $found[$col] = normalize_csv_header((string) $value);
        }
        if (array_intersect($found, $nameAliases)) {
            $headerRow = $rowNum;
            $headers = $found;
            break;
        }
    }
    if ($headerRow === null) {
        throw new RuntimeException('No header row was found. The first row needs column names such as "Medicine" and "Qty to Add".');
    }

    $rows = [];
    foreach ($cellsByRow as $rowNum => $cells) {
        if ($rowNum <= $headerRow) {
            continue;
        }
        $row = ['_line' => $rowNum];
        $any = false;
        foreach ($headers as $col => $key) {
            if ($key === '') {
                continue;
            }
            $value = $cells[$col] ?? '';
            // Excel stores dates as day counts (e.g. 46023). Turn a number in
            // the plausible range under an expiry heading into a real date.
            if (is_numeric($value) && in_array($key, STOCK_IMPORT_ALIASES['expiry'], true) && $value > 20000 && $value < 80000) {
                $value = gmdate('Y-m-d', (int) round(($value - 25569) * 86400));
            }
            $value = trim((string) $value);
            if ($value !== '') {
                $any = true;
            }
            $row[$key] = $value;
        }
        if ($any) {
            $rows[] = $row;
        }
        if (count($rows) > STOCK_IMPORT_MAX_ROWS) {
            throw new RuntimeException('This file has more than ' . STOCK_IMPORT_MAX_ROWS . ' rows. Please split it into smaller files.');
        }
    }
    if (empty($rows)) {
        throw new RuntimeException('The file has a header row but no data rows.');
    }
    return $rows;
}

/** First non-empty value among a row's aliased columns, or null when none of them exist in the file. */
function imp_pick(array $row, string $field): ?string
{
    foreach (STOCK_IMPORT_ALIASES[$field] as $alias) {
        if (array_key_exists($alias, $row)) {
            return trim((string) $row[$alias]);
        }
    }
    return null;
}

/** Loads the catalog and the lookup indexes used for matching. */
function imp_load_catalog(mysqli $conn): array
{
    $cat = ['byId' => [], 'byName' => [], 'byGenStr' => [], 'byLoose' => []];
    $res = $conn->query(
        'SELECT im.medicine_id, im.name, im.strength, im.unit, im.current_stock, mn.name AS generic_name
         FROM inventory_medicines im
         LEFT JOIN medicine_names mn ON mn.generic_id = im.generic_id
         ORDER BY im.name ASC'
    );
    while ($m = $res->fetch_assoc()) {
        $id = (int) $m['medicine_id'];
        $cat['byId'][$id] = $m;
        $cat['byName'][imp_norm($m['name'])][$id] = true;
        $cat['byLoose'][imp_loose($m['name'])][$id] = true;
        if ($m['generic_name'] !== null) {
            $strength = (string) $m['strength'];
            $cat['byGenStr'][imp_norm($m['generic_name']) . '|' . imp_norm($strength)][$id] = true;
            $cat['byLoose'][imp_loose(trim($m['generic_name'] . ' ' . $strength))][$id] = true;
        }
    }
    return $cat;
}

/**
 * Matches one file row to exactly one catalog medicine.
 *
 * @return array{status:string, note:string, medicine:?array}
 *   status: matched | ambiguous | unmatched
 */
function imp_match(array $cat, int $id, string $name, string $strength): array
{
    if ($id > 0 && isset($cat['byId'][$id])) {
        return ['status' => 'matched', 'note' => '', 'medicine' => $cat['byId'][$id]];
    }
    if ($name === '') {
        return ['status' => 'unmatched', 'note' => $id > 0 ? 'Medicine ID not found in the catalog' : 'No medicine name or ID on this row', 'medicine' => null];
    }

    $withStrength = $strength !== '' ? trim($name . ' ' . $strength) : '';
    $lookups = [
        $cat['byName'][imp_norm($name)] ?? null,
        $withStrength !== '' ? ($cat['byName'][imp_norm($withStrength)] ?? null) : null,
        $strength !== '' ? ($cat['byGenStr'][imp_norm($name) . '|' . imp_norm($strength)] ?? null) : null,
        $cat['byLoose'][imp_loose($name)] ?? null,
        $withStrength !== '' ? ($cat['byLoose'][imp_loose($withStrength)] ?? null) : null,
    ];
    foreach ($lookups as $candidates) {
        if (!$candidates) {
            continue;
        }
        $ids = array_keys($candidates);
        if (count($ids) === 1) {
            return ['status' => 'matched', 'note' => '', 'medicine' => $cat['byId'][$ids[0]]];
        }
        $names = array_map(function ($i) use ($cat) {
            return $cat['byId'][$i]['name'];
        }, array_slice($ids, 0, 3));
        return ['status' => 'ambiguous', 'note' => 'Matches more than one medicine (' . implode('; ', $names) . '). Use the Medicine ID column.', 'medicine' => null];
    }
    return ['status' => 'unmatched', 'note' => 'Not found in the catalog. Add it under New Medicine first.', 'medicine' => null];
}

// ---------------------------------------------------------------------
// GET: template with every catalog medicine listed
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'download_template') {
    $rows = [];
    $res = $conn->query('SELECT medicine_id, name, unit, current_stock FROM inventory_medicines ORDER BY name ASC');
    while ($m = $res->fetch_assoc()) {
        $rows[] = [$m['medicine_id'], $m['name'], $m['unit'], $m['current_stock'], '', '', '', '', ''];
    }
    csv_download('stock_import_template.csv', STOCK_IMPORT_TEMPLATE_HEADER, $rows);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['success' => false, 'error' => 'Invalid request method.'], 405);
}

$isMultipart = stripos($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data') !== false;
$body = [];
if ($isMultipart) {
    $action = $_POST['action'] ?? '';
    $submittedToken = $_POST['csrf_token'] ?? '';
} else {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $body['action'] ?? '';
    $submittedToken = $body['csrf_token'] ?? '';
}
$expectedToken = $_SESSION['csrf_token'] ?? '';
if ($expectedToken === '' || !hash_equals($expectedToken, (string) $submittedToken)) {
    respond(['success' => false, 'error' => 'Your session expired. Please refresh the page and try again.'], 403);
}

// ---------------------------------------------------------------------
// parse_upload: read + match. Changes nothing.
// ---------------------------------------------------------------------
if ($action === 'parse_upload') {
    if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] === UPLOAD_ERR_NO_FILE) {
        respond(['success' => false, 'error' => 'Please choose a file to import.'], 422);
    }
    $ext = strtolower((string) pathinfo((string) $_FILES['import_file']['name'], PATHINFO_EXTENSION));
    try {
        if ($ext === 'xlsx') {
            if ($_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException('The file could not be uploaded. Please try again.');
            }
            $rows = imp_read_xlsx('import_file');
        } elseif ($ext === 'csv' || $ext === 'txt') {
            $rows = csv_parse_upload('import_file', STOCK_IMPORT_MAX_ROWS);
            foreach ($rows as $i => $r) {
                $rows[$i]['_line'] = $i + 2; // +1 for the header row, +1 for 1-indexing
            }
        } else {
            throw new RuntimeException('Please upload a .csv or .xlsx file.');
        }
    } catch (RuntimeException $e) {
        respond(['success' => false, 'error' => $e->getMessage()], 422);
    }

    // The file has to have a quantity column and some way to identify medicines.
    $keys = array_keys($rows[0]);
    $hasQty = (bool) array_intersect(STOCK_IMPORT_ALIASES['qty'], $keys);
    $hasWho = (bool) array_intersect(array_merge(STOCK_IMPORT_ALIASES['name'], STOCK_IMPORT_ALIASES['id']), $keys);
    if (!$hasQty || !$hasWho) {
        respond(['success' => false, 'error' => 'The file needs a medicine column ("Medicine" or "Medicine ID") and a quantity column ("Qty to Add"). Download the template to see the layout.'], 422);
    }

    $cat = imp_load_catalog($conn);
    $items = [];
    $skippedBlank = 0;
    foreach ($rows as $row) {
        $line = $row['_line'];
        $idRaw = imp_pick($row, 'id') ?? '';
        $name = imp_pick($row, 'name') ?? '';
        $strength = imp_pick($row, 'strength') ?? '';
        $qtyRaw = imp_pick($row, 'qty') ?? '';

        // Template rows nobody filled in a quantity for are simply "not part of this import".
        if ($qtyRaw === '' || $qtyRaw === '0') {
            $skippedBlank++;
            continue;
        }

        $match = imp_match($cat, ctype_digit($idRaw) ? (int) $idRaw : 0, $name, $strength);
        $item = [
            'line' => $line,
            'file_name' => trim($name . ($strength !== '' && stripos($name, $strength) === false ? ' ' . $strength : '')),
            'status' => $match['status'],
            'note' => $match['note'],
            'medicine_id' => $match['medicine'] ? (int) $match['medicine']['medicine_id'] : null,
            'medicine_name' => $match['medicine']['name'] ?? '',
            'unit' => $match['medicine']['unit'] ?? '',
            'current_stock' => $match['medicine'] ? (int) $match['medicine']['current_stock'] : null,
            'qty' => 0,
            'batch_no' => '',
            'expiry_date' => '',
            'unit_price' => '',
            'selling_price' => '',
        ];
        if ($item['file_name'] === '' && $idRaw !== '') {
            $item['file_name'] = 'ID ' . $idRaw;
        }

        if (!ctype_digit($qtyRaw) || (int) $qtyRaw > STOCK_IMPORT_MAX_QTY) {
            $item['status'] = 'invalid';
            $item['note'] = 'Quantity must be a whole number above 0';
            $items[] = $item;
            continue;
        }
        $item['qty'] = (int) $qtyRaw;

        // Optional columns: a bad value is dropped with a note, it doesn't sink the row.
        $notes = [];
        $batchNo = imp_pick($row, 'batch') ?? '';
        if (strlen($batchNo) > 100) {
            $batchNo = '';
            $notes[] = 'batch number too long, left blank';
        }
        $item['batch_no'] = $batchNo;
        $expiry = imp_pick($row, 'expiry') ?? '';
        if ($expiry !== '' && !imp_valid_ymd($expiry)) {
            $notes[] = 'expiry "' . $expiry . '" is not a valid YYYY-MM-DD date, left blank';
            $expiry = '';
        }
        $item['expiry_date'] = $expiry;
        foreach (['unit_price' => 'unit price', 'selling_price' => 'selling price'] as $field => $label) {
            $input = imp_pick($row, $field) ?? '';
            if ($input === '') {
                continue;
            }
            $parsed = filter_var($input, FILTER_VALIDATE_FLOAT);
            if ($parsed === false || $parsed < 0 || $parsed > STOCK_IMPORT_MAX_PRICE) {
                $notes[] = "invalid {$label}, left blank";
            } else {
                $item[$field] = $parsed;
            }
        }
        if ($notes && $item['status'] === 'matched') {
            $item['note'] = ucfirst(implode('; ', $notes));
        }
        $items[] = $item;
    }

    if (empty($items)) {
        respond(['success' => false, 'error' => 'No rows with a quantity were found. Fill in "Qty to Add" for the medicines you want to add, then upload again.'], 422);
    }

    $matched = 0;
    $units = 0;
    foreach ($items as $it) {
        if ($it['status'] === 'matched') {
            $matched++;
            $units += $it['qty'];
        }
    }
    respond([
        'success' => true,
        'items' => $items,
        'summary' => ['rows' => count($items), 'matched' => $matched, 'units' => $units, 'left_out_no_qty' => $skippedBlank],
    ]);
}

// ---------------------------------------------------------------------
// confirm: the ONLY place stock changes
// ---------------------------------------------------------------------
if ($action === 'confirm') {
    $submitted = is_array($body['items'] ?? null) ? $body['items'] : [];
    if (empty($submitted)) {
        respond(['success' => false, 'error' => 'There is nothing selected to add.'], 422);
    }
    if (count($submitted) > STOCK_IMPORT_MAX_ROWS) {
        respond(['success' => false, 'error' => 'Too many rows in one import.'], 422);
    }

    $validated = [];
    foreach ($submitted as $i => $line) {
        $rowNo = $i + 1;
        $medicineId = filter_var($line['medicine_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($medicineId === false) {
            respond(['success' => false, 'error' => "Row {$rowNo}: missing medicine."], 422);
        }
        $qty = filter_var(trim((string) ($line['qty'] ?? '')), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => STOCK_IMPORT_MAX_QTY]]);
        if ($qty === false) {
            respond(['success' => false, 'error' => "Row {$rowNo}: enter a whole-number quantity above 0."], 422);
        }
        $batchNo = trim((string) ($line['batch_no'] ?? ''));
        if (strlen($batchNo) > 100) {
            respond(['success' => false, 'error' => "Row {$rowNo}: batch number is too long."], 422);
        }
        $expiry = trim((string) ($line['expiry_date'] ?? ''));
        if ($expiry !== '' && !imp_valid_ymd($expiry)) {
            respond(['success' => false, 'error' => "Row {$rowNo}: enter a valid expiry date."], 422);
        }
        $prices = [];
        foreach (['unit_price' => 'unit price', 'selling_price' => 'selling price'] as $field => $label) {
            $input = trim((string) ($line[$field] ?? ''));
            $prices[$field] = null;
            if ($input !== '') {
                $parsed = filter_var($input, FILTER_VALIDATE_FLOAT);
                if ($parsed === false || $parsed < 0 || $parsed > STOCK_IMPORT_MAX_PRICE) {
                    respond(['success' => false, 'error' => "Row {$rowNo}: enter a valid {$label}."], 422);
                }
                $prices[$field] = round($parsed, 2);
            }
        }
        $validated[] = [
            'medicine_id' => $medicineId,
            'qty' => $qty,
            'batch_no' => $batchNo !== '' ? $batchNo : null,
            'expiry' => $expiry !== '' ? $expiry : null,
            'unit_price' => $prices['unit_price'],
            'selling_price' => $prices['selling_price'],
        ];
    }

    $totalUnits = 0;
    $medicineCount = 0;
    $conn->begin_transaction();
    try {
        // Every medicine must still exist; checked inside the transaction so a
        // medicine removed between preview and confirm can't half-apply.
        $ids = array_values(array_unique(array_column($validated, 'medicine_id')));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $existsStmt = $conn->prepare("SELECT medicine_id FROM inventory_medicines WHERE medicine_id IN ({$placeholders}) FOR UPDATE");
        $existsStmt->bind_param(str_repeat('i', count($ids)), ...$ids);
        $existsStmt->execute();
        $found = [];
        $existsRes = $existsStmt->get_result();
        while ($r = $existsRes->fetch_assoc()) {
            $found[(int) $r['medicine_id']] = true;
        }
        $existsStmt->close();
        foreach ($ids as $id) {
            if (!isset($found[$id])) {
                throw new RuntimeException('A medicine in this import is no longer in the catalog. Nothing was added - please read the file again.');
            }
        }

        foreach ($validated as $line) {
            credit_medicine_batch(
                $conn,
                $line['medicine_id'],
                STOCK_IMPORT_SOURCE,
                null,
                $line['batch_no'],
                $line['qty'],
                $line['expiry'],
                $line['unit_price'],
                $line['selling_price'],
                $pharmacistId
            );
            $totalUnits += $line['qty'];
        }
        $medicineCount = count($ids);
        $conn->commit();
    } catch (mysqli_sql_exception $e) {
        // Before RuntimeException: mysqli_sql_exception extends it, and its
        // message is raw SQL text that must never reach the user.
        $conn->rollback();
        error_log('stock import confirm failed: ' . $e->getMessage());
        respond(['success' => false, 'error' => 'Could not add this stock. Nothing was changed - please try again.'], 500);
    } catch (RuntimeException $e) {
        $conn->rollback();
        respond(['success' => false, 'error' => $e->getMessage()], 422);
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('stock import confirm failed: ' . $e->getMessage());
        respond(['success' => false, 'error' => 'Could not add this stock. Nothing was changed - please try again.'], 500);
    }

    write_audit_log(
        $conn,
        $pharmacistId,
        'pharmacist',
        'stock_imported',
        'inventory',
        "Imported {$totalUnits} purchase order units across {$medicineCount} medicine(s) from a file (" . count($validated) . ' batch(es)).'
    );
    respond(['success' => true, 'batches' => count($validated), 'medicines' => $medicineCount, 'units' => $totalUnits]);
}

respond(['success' => false, 'error' => 'Unknown action.'], 400);
