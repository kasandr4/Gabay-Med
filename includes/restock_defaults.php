<?php
// includes/restock_defaults.php
// Default "Requested Qty" for the Staff Restock Management page.
//
// Reuses the same inputs and formula the Pharmacist's Restock Needs panel
// already uses (rolling 30-day usage from medicine_exit_log, the
// admin-configurable lead time and safety-stock percent, and each
// medicine's minimum_stock) so a quantity suggested to Staff matches
// what the Pharmacist would see for the same medicine. Unlike that panel
// this returns a number for EVERY medicine (Restock Management lists all
// of them, not just low-stock ones), so the fallback is always at least 1.

require_once __DIR__ . '/system_settings.php';

/**
 * @param array $medicines rows with medicine_id, current_stock, minimum_stock
 * @return array medicine_id => default requested quantity (int >= 1)
 */
function restock_default_quantities(mysqli $conn, array $medicines): array
{
    $leadTimeDays = get_setting_int($conn, 'reorder_default_lead_time_days', 5);
    $safetyPercent = get_setting_int($conn, 'reorder_safety_stock_percent', 20);

    $avgDailyUsage = [];
    $usageResult = $conn->query(
        "SELECT medicine_id,
                SUM(units_deducted) AS total_units,
                LEAST(DATEDIFF(CURDATE(), MIN(scanned_at)) + 1, 30) AS days_span
         FROM medicine_exit_log
         WHERE scanned_at >= (CURDATE() - INTERVAL 29 DAY)
         GROUP BY medicine_id"
    );
    if ($usageResult) {
        while ($row = $usageResult->fetch_assoc()) {
            $days = max((int) $row['days_span'], 1);
            $avgDailyUsage[(int) $row['medicine_id']] = $row['total_units'] / $days;
        }
    }

    $defaults = [];
    foreach ($medicines as $m) {
        $id = (int) $m['medicine_id'];
        $currentStock = (int) $m['current_stock'];
        $minimumStock = (int) $m['minimum_stock'];

        $qty = 0;
        if (isset($avgDailyUsage[$id]) && $avgDailyUsage[$id] > 0) {
            $base = $avgDailyUsage[$id] * $leadTimeDays;
            $reorderPoint = (int) ceil($base + $base * ($safetyPercent / 100));
            $qty = max($reorderPoint - $currentStock, $minimumStock - $currentStock);
        } elseif ($minimumStock > 0) {
            $qty = $minimumStock - $currentStock;
        }

        $defaults[$id] = max(1, (int) $qty);
    }

    return $defaults;
}

/**
 * Groups medicines into the same three Restock Needs categories the
 * Pharmacist's Purchase Requests page shows - Out of Stock / Critical,
 * Fast-Moving, and Near Expiry - using the same rules, thresholds and
 * settings, so Staff and Pharmacist see the same reasons and the same
 * suggested quantities for the same medicine.
 *
 * @param array $medicines rows with medicine_id, name, unit, current_stock, minimum_stock
 * @return array ['critical' => [...], 'fast-moving' => [...], 'near-expiry' => [...]]
 *               each entry: medicine_id, note, suggested_qty (int|null), plus a sort key
 */
function restock_needs_by_category(mysqli $conn, array $medicines): array
{
    $leadTimeDays = get_setting_int($conn, 'reorder_default_lead_time_days', 5);
    $safetyPercent = get_setting_int($conn, 'reorder_safety_stock_percent', 20);
    $expiryCriticalDays = get_setting_int($conn, 'expiry_critical_days', 7);

    $avgDailyUsage = [];
    $usageResult = $conn->query(
        "SELECT medicine_id,
                SUM(units_deducted) AS total_units,
                LEAST(DATEDIFF(CURDATE(), MIN(scanned_at)) + 1, 30) AS days_span
         FROM medicine_exit_log
         WHERE scanned_at >= (CURDATE() - INTERVAL 29 DAY)
         GROUP BY medicine_id"
    );
    if ($usageResult) {
        while ($row = $usageResult->fetch_assoc()) {
            $days = max((int) $row['days_span'], 1);
            $avgDailyUsage[(int) $row['medicine_id']] = $row['total_units'] / $days;
        }
    }

    // Shorter, lead-time-length window: lets a medicine that just went
    // critical still get a usage-based quantity even with little 30-day history.
    $avgDailyUsageShort = [];
    $shortStmt = $conn->prepare(
        "SELECT medicine_id,
                SUM(units_deducted) AS total_units,
                LEAST(DATEDIFF(CURDATE(), MIN(scanned_at)) + 1, ?) AS days_span
         FROM medicine_exit_log
         WHERE scanned_at >= (CURDATE() - INTERVAL ? DAY)
         GROUP BY medicine_id"
    );
    $leadTimeMinusOne = $leadTimeDays - 1;
    $shortStmt->bind_param('ii', $leadTimeDays, $leadTimeMinusOne);
    $shortStmt->execute();
    $shortRes = $shortStmt->get_result();
    while ($row = $shortRes->fetch_assoc()) {
        $days = max((int) $row['days_span'], 1);
        $avgDailyUsageShort[(int) $row['medicine_id']] = $row['total_units'] / $days;
    }
    $shortStmt->close();

    $expiring = [];
    $expiryStmt = $conn->prepare(
        "SELECT medicine_id, SUM(units_remaining) AS expiring_qty, MIN(expiry_date) AS soonest_expiry
         FROM medicine_batches
         WHERE units_remaining > 0 AND expiry_date IS NOT NULL
           AND expiry_date <= (CURDATE() + INTERVAL ? DAY)
         GROUP BY medicine_id"
    );
    $expiryStmt->bind_param('i', $expiryCriticalDays);
    $expiryStmt->execute();
    $expiryRes = $expiryStmt->get_result();
    while ($row = $expiryRes->fetch_assoc()) {
        $expiring[(int) $row['medicine_id']] = $row;
    }
    $expiryStmt->close();

    $today = new DateTime('today');
    $out = ['critical' => [], 'fast-moving' => [], 'near-expiry' => []];

    foreach ($medicines as $m) {
        $id = (int) $m['medicine_id'];
        $currentStock = (int) $m['current_stock'];
        $minimumStock = (int) $m['minimum_stock'];
        $unit = $m['unit'];

        $isCritical = $currentStock <= 0 || ($minimumStock > 0 && $currentStock < $minimumStock * 0.5);
        if ($isCritical) {
            $usage = $avgDailyUsage[$id] ?? ($avgDailyUsageShort[$id] ?? null);
            if ($usage !== null && $usage > 0) {
                $bulkQty = $usage * $leadTimeDays;
                $suggested = max(1, (int) ceil($bulkQty + $bulkQty * ($safetyPercent / 100)));
                if ($minimumStock > 0) {
                    $suggested = max($suggested, $minimumStock - $currentStock);
                }
            } elseif ($minimumStock > 0) {
                $suggested = max(1, $minimumStock - $currentStock);
            } else {
                $suggested = null;
            }
            $out['critical'][] = [
                'medicine_id' => $id,
                'suggested_qty' => $suggested,
                'sort' => $currentStock,
                'note' => ($currentStock <= 0
                    ? 'Out of stock.'
                    : "Critical — {$currentStock} {$unit} on hand, below half of the {$minimumStock} minimum.")
                    . ($suggested === null ? ' No usage history to size a quantity from — set one manually.' : ''),
            ];
        }

        if (!$isCritical && isset($avgDailyUsage[$id])) {
            $usage = $avgDailyUsage[$id];
            $base = $usage * $leadTimeDays;
            $reorderPoint = (int) ceil($base + $base * ($safetyPercent / 100));
            if ($currentStock <= $reorderPoint) {
                $daysLeft = $usage > 0 ? (int) floor($currentStock / $usage) : null;
                $suggested = max(1, $reorderPoint - $currentStock);
                $out['fast-moving'][] = [
                    'medicine_id' => $id,
                    'suggested_qty' => $suggested,
                    'sort' => $suggested,
                    'note' => $daysLeft !== null
                        ? "Fast-moving — about {$daysLeft} day(s) of stock left at current usage."
                        : 'Fast-moving — at or below its velocity-based reorder point.',
                ];
            }
        }

        if (isset($expiring[$id])) {
            $exp = $expiring[$id];
            $expiringQty = (int) $exp['expiring_qty'];
            $projected = $currentStock - $expiringQty;
            if ($projected < $minimumStock) {
                $daysLeft = (int) $today->diff(new DateTime($exp['soonest_expiry']))->format('%r%a');
                $expiryPhrase = $daysLeft < 0 ? 'already expired' : "expiring in {$daysLeft} day(s)";
                $out['near-expiry'][] = [
                    'medicine_id' => $id,
                    'suggested_qty' => max(1, $minimumStock - $projected),
                    'sort' => $daysLeft,
                    'note' => "{$expiringQty} {$unit} {$expiryPhrase} — replacing keeps stock above the {$minimumStock} minimum once they're gone.",
                ];
            }
        }
    }

    foreach ($out as $key => $list) {
        usort($list, function ($a, $b) {
            return $a['sort'] <=> $b['sort'];
        });
        $out[$key] = $list;
    }

    return $out;
}
