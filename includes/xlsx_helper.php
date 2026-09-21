<?php
// includes/xlsx_helper.php
//
// Reads the official OMCDH/PGOM Purchase Request & Purchase Order .xlsx
// form directly, so a pharmacist can upload the exact document the
// hospital already fills out -- no second, separate CSV re-entry needed.
//
// An .xlsx file is just a zip archive of XML files, so this uses PHP's
// built-in ZipArchive + SimpleXML (standard extensions, no Composer or
// PhpSpreadsheet dependency -- matches the rest of this project's
// native-PHP approach). It only reads; it never writes .xlsx files.
//
// The official form isn't a clean data table -- it's a print layout with
// a header block, item rows, page subtotals, an intermediate (and
// misleading -- it's really just that page's running subtotal) "TOTAL
// AMOUNT" line per printed page, "END OF PAGE" markers, and a signature
// block, all in one sheet, possibly spanning several printed pages for
// one PR. xlsx_parse_upload() below pulls out only the genuine item rows
// -- anything with a real, positive whole number in the "Item No."
// column -- and ignores everything else by construction.

/**
 * Validates and reads an uploaded .xlsx file ($_FILES[$fieldName]) in the
 * official PO/PR layout. Returns rows in the SAME shape csv_parse_upload()
 * returns (see excel_helper.php), so calling code in
 * purchase-request-actions.php / purchase-order-actions.php can process
 * the result identically regardless of whether the pharmacist uploaded a
 * CSV or the official Excel form.
 *
 * Throws RuntimeException with a user-facing message on any problem.
 */
function xlsx_parse_upload(string $fieldName, int $maxRows = 500): array
{
    if (!isset($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] === UPLOAD_ERR_NO_FILE) {
        throw new RuntimeException('Please choose a file to upload.');
    }
    $file = $_FILES[$fieldName];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('The file could not be uploaded. Please try again.');
    }
    if ($file['size'] > 5 * 1024 * 1024) {
        throw new RuntimeException('The file is too large (5MB max).');
    }

    $items = xlsx_extract_po_pr_items($file['tmp_name']);
    if (empty($items)) {
        throw new RuntimeException('No item rows were found. Make sure this is the official PO/PR format with an "Item No." / "Item Description" table.');
    }
    if (count($items) > $maxRows) {
        throw new RuntimeException("This file has more than {$maxRows} item rows. Please split it into smaller batches.");
    }

    // Reshape into the same normalized-key row format csv_parse_upload()
    // produces, so the existing per-row loop in the *-actions.php files
    // works unchanged for either source. Fields the official form simply
    // doesn't have (Brand, Units per Box, Funding Source) come back
    // empty -- the pharmacist fills those in the review grid, same as
    // any CSV row missing an optional column.
    $rows = [];
    foreach ($items as $item) {
        $qty = $item['qty'] !== null ? (string) $item['qty'] : '';
        $price = $item['unit_cost'] !== null ? (string) $item['unit_cost'] : '';
        $rows[] = [
            'generic_name' => $item['generic_name'],
            'brand' => '',
            'strength' => '',
            'unit' => $item['unit'] ?? '',
            'units_per_box' => '',
            // Both keys included so this same row works whether the
            // caller is the PR action file (expects quantity_requested)
            // or the PO action file (expects quantity_ordered).
            'quantity_requested' => $qty,
            'quantity_ordered' => $qty,
            'estimated_unit_price' => $price,
            'funding_source' => '',
            'notes' => '',
        ];
    }
    return $rows;
}

/**
 * Opens an .xlsx file and returns its item rows: Item No., Quantity,
 * Unit of Issue, Item Description (split into generic name + a
 * best-effort unit parsed from the trailing parenthetical, e.g.
 * "Amlodipine besilate 10mg (Tablet)"), Estimated Unit Cost.
 *
 * A row counts as a genuine item row only if its "Item No." column is a
 * real positive whole number -- this is what makes the function skip
 * every header, subtotal, "TOTAL AMOUNT", "END OF PAGE", and signature
 * row automatically, without needing to know exactly which row numbers
 * those fall on (which varies with how many items and pages the PR has).
 */
function xlsx_extract_po_pr_items(string $path): array
{
    $cellsByRow = xlsx_read_first_sheet($path);

    // Find which columns hold "Item No." and "Item Description" by
    // reading the header row itself, rather than hardcoding column
    // letters -- the official template has always used A/B/C/D/G/H so
    // far, but this keeps the parser from silently reading the wrong
    // columns if a future revision of the form shifts anything.
    $columns = null;
    foreach ($cellsByRow as $cells) {
        $found = [];
        foreach ($cells as $col => $value) {
            $label = strtolower(trim((string) $value));
            if ($label === 'item no.' || $label === 'item no') {
                $found['item_no'] = $col;
            } elseif ($label === 'quantity') {
                $found['qty'] = $col;
            } elseif ($label === 'item description') {
                $found['description'] = $col;
            } elseif (strpos($label, 'estimated unit cost') !== false) {
                $found['unit_cost'] = $col;
            }
        }
        if (isset($found['item_no'], $found['description'])) {
            $columns = $found;
            break;
        }
    }
    if ($columns === null) {
        throw new RuntimeException('This doesn\'t look like the official PO/PR format -- no "Item No." / "Item Description" table header was found.');
    }

    $items = [];
    foreach ($cellsByRow as $cells) {
        $itemNo = $cells[$columns['item_no']] ?? null;
        if (!is_numeric($itemNo) || (int) $itemNo != $itemNo || $itemNo <= 0) {
            continue; // not a real item row -- header, subtotal, total, signature, etc.
        }
        $desc = $cells[$columns['description']] ?? null;
        if (!is_string($desc) || trim($desc) === '') {
            continue;
        }
        $desc = trim($desc);

        // The real dispensing unit (Tablet/Capsule/Vial/...) usually sits
        // in a trailing parenthetical on the description, not the sheet's
        // "Unit of Issue" column (which is just a packaging count like
        // "pc" or "bottle"). Two shapes show up in practice:
        //   1. "...10mg (Tablet)"                        -> unit "Tablet"
        //   2. "...600mg (Powder for oral solution) Sachet" -> here the
        //      parenthetical is a form note, not the unit -- the real
        //      unit is the word trailing it ("Sachet"), so that's checked
        //      first and takes priority over the parenthetical itself.
        // Not every row matches either shape -- some descriptions end
        // without a recognizable dosage form, and that's fine: the
        // pharmacist fills the unit in manually for those in the review
        // grid, the same as any other missing field.
        $genericName = $desc;
        $unit = null;
        if (preg_match('/^(.*\))\s+([^()]+)$/', $desc, $m)) {
            $genericName = trim($m[1]);
            $unit = trim($m[2]);
        } elseif (preg_match('/^(.*)\(([^()]+)\)\s*$/', $desc, $m)) {
            $genericName = trim($m[1]);
            $unit = trim($m[2]);
        }

        $qtyRaw = isset($columns['qty']) ? ($cells[$columns['qty']] ?? null) : null;
        $costRaw = isset($columns['unit_cost']) ? ($cells[$columns['unit_cost']] ?? null) : null;

        $items[] = [
            'generic_name' => $genericName,
            'unit' => $unit,
            'qty' => is_numeric($qtyRaw) ? $qtyRaw + 0 : null,
            'unit_cost' => is_numeric($costRaw) ? $costRaw + 0 : null,
        ];
    }
    return $items;
}

/**
 * Reads the first worksheet of an .xlsx file and returns its cells as
 * [rowNumber => [columnLetter => value]]. Handles xlsx's shared-strings
 * table (repeated text, like every header label, is stored once and
 * referenced by index from each cell rather than duplicated).
 */
function xlsx_read_first_sheet(string $path): array
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('This file could not be opened. Please make sure it\'s a valid .xlsx file.');
    }

    $sharedStrings = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml !== false) {
        $ss = @simplexml_load_string($ssXml);
        if ($ss !== false) {
            foreach ($ss->si as $si) {
                if (isset($si->t)) {
                    $sharedStrings[] = (string) $si->t;
                } else {
                    $text = '';
                    foreach ($si->r as $r) {
                        $text .= (string) $r->t;
                    }
                    $sharedStrings[] = $text;
                }
            }
        }
    }

    // Resolve the first sheet's actual internal filename via
    // workbook.xml + its rels, rather than assuming sheet1.xml -- a
    // workbook that's had sheets added/removed/reordered in Excel can
    // end up with its first visible sheet stored under a different
    // internal name.
    $sheetPath = 'xl/worksheets/sheet1.xml';
    $workbookXml = $zip->getFromName('xl/workbook.xml');
    $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($workbookXml !== false && $relsXml !== false) {
        $wb = @simplexml_load_string($workbookXml);
        $rels = @simplexml_load_string($relsXml);
        if ($wb !== false && $rels !== false && isset($wb->sheets->sheet[0])) {
            $wbNs = $wb->sheets->sheet[0]->attributes('r', true);
            $rId = (string) $wbNs['id'];
            foreach ($rels->Relationship as $rel) {
                if ((string) $rel['Id'] === $rId) {
                    // The Target is relative to xl/ ("worksheets/sheet1.xml",
                    // what Excel writes) or, in files saved by Google Sheets
                    // and some other tools, absolute from the archive root
                    // ("/xl/worksheets/sheet1.xml"). The old code prefixed
                    // "xl/" to both, so absolute paths became "xl/xl/..." and
                    // the sheet was never found.
                    $target = (string) $rel['Target'];
                    $sheetPath = ($target !== '' && $target[0] === '/') ? ltrim($target, '/') : 'xl/' . $target;
                    break;
                }
            }
        }
    }

    $sheetXml = $zip->getFromName($sheetPath);
    $zip->close();
    if ($sheetXml === false) {
        throw new RuntimeException('This file could not be read as an Excel workbook.');
    }

    $sheet = @simplexml_load_string($sheetXml);
    if ($sheet === false) {
        throw new RuntimeException('This file could not be read as an Excel workbook.');
    }

    $rows = [];
    foreach ($sheet->sheetData->row as $rowXml) {
        $rowNum = (int) $rowXml['r'];
        $cells = [];
        foreach ($rowXml->c as $c) {
            $ref = (string) $c['r'];
            if (!preg_match('/^([A-Z]+)/', $ref, $m)) {
                continue;
            }
            $col = $m[1];
            $type = (string) $c['t'];
            // Inline strings keep their text in <is><t> (or <is><r><t> runs),
            // not in <v>. Several tools write text this way instead of using
            // the shared-strings table; without this those cells were
            // skipped and the sheet looked empty.
            if ($type === 'inlineStr') {
                $inline = '';
                if (isset($c->is->t)) {
                    $inline = (string) $c->is->t;
                } elseif (isset($c->is->r)) {
                    foreach ($c->is->r as $run) {
                        $inline .= (string) $run->t;
                    }
                }
                $cells[$col] = $inline;
                continue;
            }
            $rawValue = isset($c->v) ? (string) $c->v : null;
            if ($rawValue === null) {
                continue;
            }
            if ($type === 's') {
                $cells[$col] = $sharedStrings[(int) $rawValue] ?? '';
            } elseif ($type === 'str' || $type === 'inlineStr') {
                $cells[$col] = $rawValue;
            } else {
                $cells[$col] = is_numeric($rawValue) ? $rawValue + 0 : $rawValue;
            }
        }
        if (!empty($cells)) {
            $rows[$rowNum] = $cells;
        }
    }
    return $rows;
}
