<?php
// pharmacist/reorder-insights-actions.php
//
// Turns Reorder Insights' "Reorder Now" list into a downloadable CSV in
// the exact same column shape Purchase Requests' own Import-from-file
// tab expects (see PR_TEMPLATE_HEADER in purchase-request-actions.php).
// A pharmacist can download this here and upload it straight into a new
// Purchase Request -- generic name, category, strength, unit, and a
// suggested quantity all arrive pre-filled, no manual re-entry.
//
// Read-only GET download, no state changes -- same as PR's own
// download_template action, so no CSRF check applies here.

require_once '../includes/auth_guard.php';
require_role('pharmacist');
require_once '../config/db.php';
require_once '../includes/excel_helper.php';
require_once '../includes/reorder_point.php';

// Identical header to PR_TEMPLATE_HEADER in purchase-request-actions.php
// -- this is what makes the file round-trip through PR's existing
// Import-from-file tab (and its Medicine Catalog auto-fill) unchanged.
// Estimated Unit Price sits in the same column position PR's template
// uses it in, so it pre-fills the grid's "Est. unit price" field the
// same way Quantity Requested already pre-fills Qty. Suggested Amount
// to Restock is this export's own extra column, not part of PR's
// template -- PR's parser ignores unrecognized columns, so it's safe
// to include here without affecting the round-trip.
const REORDER_LIST_HEADER = ['Generic Name', 'Brand', 'Strength', 'Unit', 'Units per Box', 'Quantity Requested', 'Estimated Unit Price', 'Funding Source', 'Notes', 'Suggested Amount to Restock'];

if (($_GET['action'] ?? '') !== 'download_reorder_list') {
    http_response_code(400);
    echo 'Unknown action.';
    exit;
}

// CATEGORY RETIRED: this export used to exclude Medical Supplies items
// (gloves, syringes) -- leftover demo rows, since removed along with
// medicine_names.category. Same compute_reorder_point_for_medicine()
// formula reorder-insights.php and dispense-actions.php both already
// use -- so this export can never disagree with what the report page
// shows for the same medicine.
$medicines = [];
$result = $conn->query(
    "SELECT im.medicine_id, im.name, mn.name AS generic_name,
            im.strength, im.unit, im.units_per_box, im.current_stock
     FROM inventory_medicines im
     JOIN medicine_names mn ON mn.generic_id = im.generic_id
     ORDER BY im.name ASC"
);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $medicines[] = $row;
    }
}

// Latest known unit price per medicine, from medicine_batches -- the
// only place actual cost is ever recorded (inventory_medicines has no
// price field of its own). Picks each medicine's most recent batch
// that actually has a price on it (donated/no-price batches are common
// and would otherwise mask a real, older price with a NULL). One
// batched query keyed off received_at rather than a per-medicine
// lookup in the loop below, same reasoning as reorder-insights.php's
// own $avgDailyUsageByMedicine query.
$latestUnitPriceByMedicine = [];
$priceResult = $conn->query(
    "SELECT mb.medicine_id, mb.unit_price
     FROM medicine_batches mb
     INNER JOIN (
         SELECT medicine_id, MAX(received_at) AS latest_received_at
         FROM medicine_batches
         WHERE unit_price IS NOT NULL
         GROUP BY medicine_id
     ) latest ON latest.medicine_id = mb.medicine_id AND latest.latest_received_at = mb.received_at
     WHERE mb.unit_price IS NOT NULL"
);
if ($priceResult) {
    while ($row = $priceResult->fetch_assoc()) {
        // A tie on received_at (two batches logged the same instant)
        // would return more than one row for that medicine_id here --
        // keep whichever arrives first, it's an edge case either price
        // is equally "latest."
        $medicineId = (int) $row['medicine_id'];
        if (!isset($latestUnitPriceByMedicine[$medicineId])) {
            $latestUnitPriceByMedicine[$medicineId] = (float) $row['unit_price'];
        }
    }
}

// Best-effort split of a name like "Cinnarizine 75mg" into
// ["Cinnarizine", "75mg"] -- used only as a fallback below, when a
// medicine's strength was never entered into its own column and instead
// got typed straight into the name at catalog-entry time. Splits at the
// first digit, since drug names themselves don't start with one and the
// dosage phrase -- single ("75mg"), combination ("200/100mg/5ml"), or
// with a trailing volume ("...5ml, 120ml") -- always does. This is a
// heuristic, not a guarantee: a name where the digit comes from the
// product itself rather than a dose (e.g. "D5 Water") would split wrong.
// That's an acceptable trade-off for a pre-fill the pharmacist reviews
// before saving anyway, same as every other auto-filled field here.
function split_name_and_strength(string $fullName): array
{
    $working = trim(preg_replace('/\s*\([^()]*\)\s*$/', '', $fullName));
    if (preg_match('/^(.*?)\s+(\d.*)$/', $working, $m)) {
        return [trim($m[1]), trim($m[2])];
    }
    return [$working, ''];
}

$rows = [];
foreach ($medicines as $m) {
    $medicineId = (int) $m['medicine_id'];
    $calc = compute_reorder_point_for_medicine($conn, $medicineId);
    $currentStock = (int) $m['current_stock'];
    $reorderPoint = $calc['reorder_point'];

    // Only the "Reorder Now" band belongs on a restock list -- "Monitor"
    // is explicitly "not yet, but watch it," and has no defined target
    // quantity to suggest here.
    if ($currentStock > $reorderPoint) {
        continue;
    }

    // Guaranteed >= 0 by the check above; floor at 1 so a medicine right
    // at its reorder point still shows up with something to request
    // instead of silently vanishing from the list.
    $suggestedQty = max($reorderPoint - $currentStock, 1);

    $daysLeft = $calc['avg_daily_usage'] > 0 ? (int) floor($currentStock / $calc['avg_daily_usage']) : null;
    $notes = $calc['no_usage_data']
        ? 'From Reorder Insights (no usage data yet)'
        : "From Reorder Insights ({$daysLeft}d of stock left)";

    $genericName = $m['generic_name'];
    $strength = $m['strength'];
    if ($strength === null || trim((string) $strength) === '') {
        [$extractedName, $extractedStrength] = split_name_and_strength($genericName);
        if ($extractedStrength !== '') {
            $genericName = $extractedName;
            $strength = $extractedStrength;
        }
    }

    // Blank, not zero, when no batch has ever recorded a price for this
    // medicine -- same "don't guess a number" call this file already
    // makes for $noUsageData above. A pharmacist filling this in by hand
    // shouldn't mistake a blank for "free."
    $unitPrice = $latestUnitPriceByMedicine[$medicineId] ?? null;
    $suggestedAmount = $unitPrice !== null ? round($unitPrice * $suggestedQty, 2) : '';

    $rows[] = [
        $genericName,
        '', // Brand -- not tracked at the generic/reorder level
        $strength,
        $m['unit'],
        $m['units_per_box'],
        $suggestedQty,
        $unitPrice !== null ? $unitPrice : '',
        // Funding Source -- defaults to 'purchase_order' since a reorder-
        // triggered restock is the standard PO channel in practice
        // (matches the sample row in PR_TEMPLATE_HEADER's own downloadable
        // template). Still a normal editable dropdown per row, so a
        // medicine actually earmarked for MAIP/PhilHealth/PHO/Donated
        // just gets switched there before saving.
        'purchase_order',
        $notes,
        $suggestedAmount,
    ];
}

csv_download('reorder_list_' . date('Y-m-d') . '.csv', REORDER_LIST_HEADER, $rows);
