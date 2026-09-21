<?php
// pharmacist/includes/monthly_report_helpers.php
// Shared by reports.php (Monthly Inventory Report tab) and monthly-report-export.php.
//
// monthBounds(), sumByMedicine() and getMonthlyInventorySnapshot() were moved here
// from reports.php. The stock math is unchanged; the snapshot now also returns each
// medicine's minimum stock, a status, and a below-minimum count in its summary.


function monthBounds($monthKey)
{
    $start = $monthKey . '-01 00:00:00';
    $end = date('Y-m-d 23:59:59', strtotime($start . ' +1 month -1 day'));
    return [$start, $end];
}

/**
 * Total of one column from one table, grouped by medicine_id, for rows
 * where dateCol is >= or > a given boundary. Used by the Stock
 * Comparison tab to reconstruct stock at an arbitrary point in time
 * across the whole catalog in one query instead of one per medicine.
 */
function sumByMedicine(mysqli $conn, string $table, string $sumCol, string $dateCol, string $op, string $boundary): array
{
    $sums = [];
    $stmt = $conn->prepare("SELECT medicine_id, SUM({$sumCol}) AS total FROM {$table} WHERE {$dateCol} {$op} ? GROUP BY medicine_id");
    $stmt->bind_param('s', $boundary);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $sums[(int) $row['medicine_id']] = (int) $row['total'];
    }
    $stmt->close();
    return $sums;
}

function getMonthlyInventorySnapshot(mysqli $conn, array $catalog, string $monthKey, string $currentMonthKey): array
{
    [$monthStart, $monthEnd] = monthBounds($monthKey);
    $now = new DateTime();
    $effectiveEnd = min(new DateTime($monthEnd), $now)->format('Y-m-d H:i:s');
    $isCurrent = $monthKey === $currentMonthKey;

    $receivedFromStart = sumByMedicine($conn, 'medicine_batches', 'units_received', 'received_at', '>=', $monthStart);
    $dispensedFromStart = sumByMedicine($conn, 'medicine_exit_log', 'units_deducted', 'scanned_at', '>=', $monthStart);
    $receivedAfterEnd = sumByMedicine($conn, 'medicine_batches', 'units_received', 'received_at', '>', $effectiveEnd);
    $dispensedAfterEnd = sumByMedicine($conn, 'medicine_exit_log', 'units_deducted', 'scanned_at', '>', $effectiveEnd);

    $rows = [];
    $summary = ['received' => 0, 'dispensed' => 0, 'activity_medicines' => 0, 'out_of_stock_end' => 0, 'below_minimum' => 0];

    foreach ($catalog as $medicineId => $m) {
        $currentStock = (int) $m['current_stock'];
        $recFromStart = $receivedFromStart[$medicineId] ?? 0;
        $dispFromStart = $dispensedFromStart[$medicineId] ?? 0;
        $recAfterEnd = $receivedAfterEnd[$medicineId] ?? 0;
        $dispAfterEnd = $dispensedAfterEnd[$medicineId] ?? 0;

        $stockAtStart = $currentStock - $recFromStart + $dispFromStart;
        $stockAtEnd = $currentStock - $recAfterEnd + $dispAfterEnd;
        $receivedInMonth = $recFromStart - $recAfterEnd;
        $dispensedInMonth = $dispFromStart - $dispAfterEnd;
        $hasMeaningfulData = $stockAtStart !== 0 || $stockAtEnd !== 0 || $receivedInMonth !== 0 || $dispensedInMonth !== 0;
        $hasActivity = $receivedInMonth !== 0 || $dispensedInMonth !== 0;
        $minimum = (int) ($m['minimum_stock'] ?? 0);
        $status = monthlyReportStatus($stockAtEnd, $minimum, $hasActivity);

        if ($hasActivity) {
            $summary['activity_medicines']++;
        }
        if ($stockAtEnd <= 0 && $hasMeaningfulData) {
            $summary['out_of_stock_end']++;
        }
        if ($status === 'low' && $hasMeaningfulData) {
            $summary['below_minimum']++;
        }

        $summary['received'] += $receivedInMonth;
        $summary['dispensed'] += $dispensedInMonth;

        $rows[$medicineId] = [
            'medicine_id' => $medicineId,
            'name' => $m['name'],
            'unit' => $m['unit'],
            'stock_start' => $stockAtStart,
            'received' => $receivedInMonth,
            'dispensed' => $dispensedInMonth,
            'stock_end' => $stockAtEnd,
            'minimum' => $minimum,
            'status' => $status,
            'has_meaningful_data' => $hasMeaningfulData,
            'has_activity' => $hasActivity,
        ];
    }

    return [
        'rows' => $rows,
        'summary' => $summary,
        'is_current' => $isCurrent,
        'effective_end' => $effectiveEnd,
    ];
}

/**
 * Status of one medicine at the end of the report month.
 * out = zero (or less) stock, low = under minimum_stock, none = no movement, ok = fine.
 */
function monthlyReportStatus(int $stockEnd, int $minimum, bool $hasActivity): string
{
    if ($stockEnd <= 0) {
        return 'out';
    }
    if ($minimum > 0 && $stockEnd < $minimum) {
        return 'low';
    }
    if (!$hasActivity) {
        return 'none';
    }
    return 'ok';
}

/** [label, css class] for a status key. Classes already exist in pharmacist-dashboard.css, except rp-pill-none (defined in reports.php). */
function monthlyReportStatusMeta(string $status): array
{
    switch ($status) {
        case 'out':
            return ['Out of stock', 'status-out-of-stock'];
        case 'low':
            return ['Below minimum', 'status-low-stock'];
        case 'none':
            return ['No movement', 'rp-pill-none'];
        default:
            return ['OK', 'status-normal'];
    }
}

function monthlyReportIsMonthKey($value): bool
{
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}$/', $value)) {
        return false;
    }
    $d = DateTime::createFromFormat('!Y-m', $value);
    return $d !== false && $d->format('Y-m') === $value;
}

/**
 * Reads and validates the report's GET params. Anything invalid falls back to a safe default.
 */
function monthlyReportParams(array $get, string $currentMonthKey): array
{
    $month = $get['month'] ?? $currentMonthKey;
    if (!monthlyReportIsMonthKey($month) || $month > $currentMonthKey) {
        $month = $currentMonthKey;
    }

    $filter = $get['filter'] ?? 'all';
    if (!is_string($filter) || !in_array($filter, ['all', 'activity', 'low', 'out', 'none'], true)) {
        $filter = 'all';
    }

    $sort = $get['sort'] ?? 'name';
    if (!is_string($sort) || !in_array($sort, ['name', 'dispensed', 'closing'], true)) {
        $sort = 'name';
    }

    $q = isset($get['q']) && is_string($get['q']) ? substr(trim($get['q']), 0, 80) : '';
    $page = max(1, (int) ($get['page'] ?? 1));

    return [
        'month' => $month,
        'filter' => $filter,
        'sort' => $sort,
        'q' => $q,
        'page' => $page,
    ];
}

/** Query string for links, leaving out values that equal the defaults. */
function monthlyReportQuery(array $p, array $override = []): string
{
    $q = array_merge([
        'month' => $p['month'],
        'filter' => $p['filter'],
        'sort' => $p['sort'],
        'q' => $p['q'],
        'page' => $p['page'],
    ], $override);

    foreach ($q as $key => $value) {
        if (
            $value === '' || $value === null
            || ($key === 'filter' && $value === 'all')
            || ($key === 'sort' && $value === 'name')
            || ($key === 'page' && (int) $value <= 1)
        ) {
            unset($q[$key]);
        }
    }
    return http_build_query($q);
}

function monthlyReportLoadCatalog(mysqli $conn): array
{
    $catalog = [];
    $result = $conn->query(
        "SELECT medicine_id, name, unit, current_stock, minimum_stock FROM inventory_medicines ORDER BY name ASC"
    );
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $catalog[(int) $row['medicine_id']] = $row;
        }
    }
    return $catalog;
}

/** Report rows for the medicines that have stock or movement in the report month. */
function monthlyReportBuildRows(array $snapshot): array
{
    $rows = [];
    foreach ($snapshot['rows'] as $medicineId => $row) {
        if (!$row['has_meaningful_data']) {
            continue;
        }
        $rows[] = $row;
    }
    return $rows;
}

function monthlyReportFilterCounts(array $rows): array
{
    $counts = ['all' => count($rows), 'activity' => 0, 'low' => 0, 'out' => 0, 'none' => 0];
    foreach ($rows as $row) {
        if ($row['has_activity']) {
            $counts['activity']++;
        } else {
            $counts['none']++;
        }
        if ($row['status'] === 'low') {
            $counts['low']++;
        } elseif ($row['status'] === 'out') {
            $counts['out']++;
        }
    }
    return $counts;
}

function monthlyReportFilterRows(array $rows, string $q, string $filter, string $sort): array
{
    $rows = array_values(array_filter($rows, static function ($row) use ($q, $filter) {
        if ($q !== '' && stripos($row['name'], $q) === false) {
            return false;
        }
        switch ($filter) {
            case 'activity':
                return $row['has_activity'];
            case 'low':
                return $row['status'] === 'low';
            case 'out':
                return $row['status'] === 'out';
            case 'none':
                return !$row['has_activity'];
        }
        return true;
    }));

    usort($rows, static function ($a, $b) use ($sort) {
        if ($sort === 'dispensed' && $a['dispensed'] !== $b['dispensed']) {
            return $b['dispensed'] <=> $a['dispensed'];
        }
        if ($sort === 'closing' && $a['stock_end'] !== $b['stock_end']) {
            return $a['stock_end'] <=> $b['stock_end'];
        }
        return strcasecmp($a['name'], $b['name']);
    });

    return $rows;
}

function monthlyReportPaginate(array $rows, int $page, int $perPage = 25): array
{
    $total = count($rows);
    $pages = max(1, (int) ceil($total / $perPage));
    $page = min(max(1, $page), $pages);
    $offset = ($page - 1) * $perPage;

    return [
        'rows' => array_slice($rows, $offset, $perPage),
        'page' => $page,
        'pages' => $pages,
        'total' => $total,
        'from' => $total > 0 ? $offset + 1 : 0,
        'to' => min($total, $offset + $perPage),
    ];
}

/** Everything the page and the export need, in one call. */
function monthlyReportBuild(mysqli $conn, array $params, string $currentMonthKey): array
{
    $catalog = monthlyReportLoadCatalog($conn);
    $snapshot = getMonthlyInventorySnapshot($conn, $catalog, $params['month'], $currentMonthKey);
    $allRows = monthlyReportBuildRows($snapshot);
    return [
        'snapshot' => $snapshot,
        'allRows' => $allRows,
        'counts' => monthlyReportFilterCounts($allRows),
        'rows' => monthlyReportFilterRows($allRows, $params['q'], $params['filter'], $params['sort']),
    ];
}
