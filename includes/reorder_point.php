<?php
// includes/reorder_point.php
// Shared reorder-point math, factored out so dispense-actions.php's
// critical-stock notification trigger uses the EXACT same formula
// pharmacist/reorder-insights.php shows on screen — a pharmacist
// shouldn't see one number on the report and get notified against a
// different one.
//
// reorder_point = (avg_daily_usage x lead_time_days) + 20% safety buffer,
// falling back to minimum_stock when a medicine has no usage history yet
// to compute a velocity from. See reorder-insights.php's header comment
// for the full reasoning behind this formula.
//
// FIXED (2026-08-22): the historical-lead-time query against
// purchase_order_deliveries/purchase_orders/purchase_requests broke when
// 018_replace_procurement_with_funding_source.sql dropped all three
// tables — there's no "order placed" timestamp anymore to compare
// against a "received" timestamp (Record Stock Batch logs stock the
// instant it arrives, it doesn't track a separate order date). Rather
// than invent a substitute lead-time proxy from medicine_batches.received_at
// gaps — which would measure how often this pharmacy happens to restock,
// not how long an order takes, and presenting that as "lead time" would
// be misleading, not just imprecise — lead_time_days now always uses
// the admin-configurable reorder_default_lead_time_days setting. See
// reorder-insights.php's matching fix for the report-page copy of this
// same formula.

require_once __DIR__ . '/system_settings.php';

// FORMERLY hardcoded constants — now admin-editable via
// admin/system-settings.php (system_settings table, category
// 'inventory').

/**
 * Returns ['avg_daily_usage', 'lead_time_days', 'reorder_point', 'no_usage_data']
 * for a single medicine_id, using the same rolling-30-day usage window
 * as reorder-insights.php. lead_time_days is always the configured
 * default (see FIXED note above) — no historical lead time is computable.
 */
function compute_reorder_point_for_medicine($conn, int $medicineId): array
{
    $usageStmt = $conn->prepare(
        "SELECT SUM(units_deducted) AS total_units,
                LEAST(DATEDIFF(CURDATE(), MIN(scanned_at)) + 1, 30) AS days_span
         FROM medicine_exit_log
         WHERE medicine_id = ? AND scanned_at >= (CURDATE() - INTERVAL 29 DAY)"
    );
    $usageStmt->bind_param("i", $medicineId);
    $usageStmt->execute();
    $usage = $usageStmt->get_result()->fetch_assoc();
    $usageStmt->close();

    $noUsageData = empty($usage['total_units']);
    $avgDailyUsage = 0.0;
    if (!$noUsageData) {
        $days = max((int) $usage['days_span'], 1);
        $avgDailyUsage = $usage['total_units'] / $days;
    }

    $leadTimeDays = get_setting_int($conn, 'reorder_default_lead_time_days', 5);

    $minStmt = $conn->prepare("SELECT minimum_stock FROM inventory_medicines WHERE medicine_id = ?");
    $minStmt->bind_param("i", $medicineId);
    $minStmt->execute();
    $minimumStock = (int) ($minStmt->get_result()->fetch_assoc()['minimum_stock'] ?? 0);
    $minStmt->close();

    if ($noUsageData) {
        // No velocity to compute from — same fallback reorder-insights.php
        // uses: the old flat minimum_stock rule, rather than guessing.
        $reorderPoint = $minimumStock;
    } else {
        $safetyPercent = get_setting_int($conn, 'reorder_safety_stock_percent', 20);
        $base = $avgDailyUsage * $leadTimeDays;
        $safety = $base * ($safetyPercent / 100);
        $reorderPoint = (int) ceil($base + $safety);
    }

    return [
        "avg_daily_usage" => $avgDailyUsage,
        "lead_time_days"  => $leadTimeDays,
        "reorder_point"   => $reorderPoint,
        "no_usage_data"   => $noUsageData,
    ];
}
