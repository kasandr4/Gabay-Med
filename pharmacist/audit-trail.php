<?php
// pharmacist/audit-trail.php
// Pharmacist-scoped operational audit history.
//
// TABBED LAYOUT (2026-09-06): previously a single flat table with a
// <select> to filter by module. Restructured to tab navigation matching
// reports.php's .rp-tabs/.rp-tab pattern, since that page already
// established the visual convention for "one hub, several distinct
// views" in this portal. "All Activity" is kept as a first tab
// (reports.php has no equivalent) because an audit trail's common case
// really is "show me everything for this user/date range", not just one
// module at a time.
//
// DISPENSE LOG MERGED IN (2026-09-06): the Dispensing tab now reads from
// medicine_exit_log directly instead of audit_log, absorbing what used
// to be the separate pharmacist/dispense-log.php page (that file and its
// sidebar entry have been removed - this tab replaces it entirely).
// Reasoning: medicine_exit_log is strictly richer for this purpose - one
// row per FEFO batch actually drawn from (batch number, expiry date),
// which audit_log's one-row-per-dispense-action summary can't show - and,
// since 022/023's migrations, it already carries prescription_id /
// confinement_discharge_medication_id, which is enough to join back to a
// patient without needing audit_log at all. This closes a real
// duplication: a pharmacist previously had to check two separate pages
// to see the same underlying dispensing activity, one with batch/expiry
// detail and no patient name, one with a summary and a patient name but
// no batch detail. Now there's one page for it.
//
// The other four tabs (and "All Activity") are untouched and still read
// audit_log as before - a dispense action still writes a summary row
// there too (see staff/dispense-actions.php), so it still surfaces under
// "All Activity" alongside every other module; this tab is just the
// specialized, detailed operational view for dispensing specifically.
//
// pharmacist/reports.php's own "Dispensing" tab (monthly aggregate
// stats/trends across a 6-month window) is a deliberately separate,
// different report - trends over time, not a browsable transaction list
// - and is untouched by this change.
require_once '../includes/auth_guard.php';
require_role('pharmacist');
require_once '../config/db.php';

$allowedModules = [
    'inventory' => 'Inventory',
    'medicine_catalog' => 'Medicine Catalog',
    'prescribing' => 'Prescribing',
    'dispensing' => 'Dispensing',
    'reports' => 'Reports',
];

$tab = $_GET['tab'] ?? 'all';
if ($tab !== 'all' && !array_key_exists($tab, $allowedModules)) {
    $tab = 'all';
}

$fromDate = trim($_GET['from'] ?? '');
$toDate = trim($_GET['to'] ?? '');
$search = trim($_GET['search'] ?? '');
$datePattern = '/^\d{4}-\d{2}-\d{2}$/';
if ($fromDate !== '' && !preg_match($datePattern, $fromDate)) $fromDate = '';
if ($toDate !== '' && !preg_match($datePattern, $toDate)) $toDate = '';

$rows = [];
$dispenseRows = [];

if ($tab === 'dispensing') {
    // Per-batch dispensing detail, absorbed from the former
    // pharmacist/dispense-log.php. LEFT JOINs both possible sources
    // (outpatient prescription / confinement discharge medication) to
    // resolve a patient name - a row only ever matches one of the two
    // (or neither, for a pre-migration legacy row), never both, so the
    // COALESCE below is safe rather than a real ambiguity.
    // STAFF ATTRIBUTION GUARD (see 025_cleanup_legacy_dispense_test_rows.sql):
    // joined ON u.role = 'staff' AND u.staff_type = 'inventory', not just
    // user_id, so a leftover/legacy medicine_exit_log row can never again
    // display a pharmacist (or any non-inventory-staff account) as the
    // dispensing staff here - dispense-actions.php already guarantees only
    // inventory staff can write these rows going forward, so this join is
    // just making the audit trail enforce the same rule defensively. A
    // row whose staff_id doesn't currently satisfy that (only possible for
    // old/legacy data, or a staff account since reassigned) shows
    // "(legacy staff record)" via the COALESCE below instead of silently
    // dropping the row or misattributing it.
    $sql = "SELECT mel.scanned_at, im.name AS medicine, im.unit, mel.units_deducted AS quantity,
                   COALESCE(CONCAT(u.first_name, ' ', u.last_name), '(legacy staff record)') AS staff_name,
                   mel.batch_id, mb.batch_no, mb.expiry_date,
                   mel.prescription_id, mel.confinement_discharge_medication_id,
                   COALESCE(pat1.first_name, pat2.first_name) AS patient_first,
                   COALESCE(pat1.last_name, pat2.last_name) AS patient_last
            FROM medicine_exit_log mel
            JOIN inventory_medicines im ON im.medicine_id = mel.medicine_id
            LEFT JOIN users u ON u.user_id = mel.staff_id AND u.role = 'staff' AND u.staff_type = 'inventory'
            LEFT JOIN medicine_batches mb ON mb.batch_id = mel.batch_id
            LEFT JOIN prescriptions p ON p.prescription_id = mel.prescription_id
            LEFT JOIN users pat1 ON pat1.user_id = p.patient_id
            LEFT JOIN confinement_discharge_medications cdm ON cdm.discharge_medication_id = mel.confinement_discharge_medication_id
            LEFT JOIN confinements cf ON cf.confinement_id = cdm.confinement_id
            LEFT JOIN users pat2 ON pat2.user_id = cf.patient_id
            WHERE 1=1";
    $types = '';
    $params = [];
    if ($search !== '') {
        $sql .= " AND (im.name LIKE ? OR CONCAT(u.first_name,' ',u.last_name) LIKE ?
                        OR CONCAT(pat1.first_name,' ',pat1.last_name) LIKE ?
                        OR CONCAT(pat2.first_name,' ',pat2.last_name) LIKE ?)";
        $like = '%' . $search . '%';
        $types .= 'ssss';
        array_push($params, $like, $like, $like, $like);
    }
    if ($fromDate !== '') {
        $sql .= " AND mel.scanned_at >= ?";
        $types .= 's';
        $params[] = $fromDate . ' 00:00:00';
    }
    if ($toDate !== '') {
        $sql .= " AND mel.scanned_at <= ?";
        $types .= 's';
        $params[] = $toDate . ' 23:59:59';
    }
    $sql .= " ORDER BY mel.scanned_at DESC, mel.log_id DESC LIMIT 500";

    $stmt = $conn->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $dispenseRows[] = $row;
    }
    $stmt->close();
} else {
    $sql = "SELECT a.logged_at, a.user_id, a.user_role, a.action, a.module, a.details,
                   CONCAT(u.first_name, ' ', u.last_name) AS user_name,
                   CONCAT(pat.first_name, ' ', pat.last_name) AS patient_name
            FROM audit_log a
            LEFT JOIN users u ON u.user_id = a.user_id
            LEFT JOIN users pat ON pat.user_id = a.patient_id
            WHERE a.module IN ('inventory', 'medicine_catalog', 'prescribing', 'dispensing', 'reports')";
    $types = '';
    $params = [];
    if ($tab !== 'all') {
        $sql .= ' AND a.module = ?';
        $types .= 's';
        $params[] = $tab;
    }
    if ($fromDate !== '') {
        $sql .= ' AND a.logged_at >= ?';
        $types .= 's';
        $params[] = $fromDate . ' 00:00:00';
    }
    if ($toDate !== '') {
        $sql .= ' AND a.logged_at <= ?';
        $types .= 's';
        $params[] = $toDate . ' 23:59:59';
    }
    if ($search !== '') {
        $sql .= " AND (a.action LIKE ? OR a.details LIKE ? OR a.module LIKE ?
                        OR CONCAT(u.first_name, ' ', u.last_name) LIKE ?
                        OR CONCAT(pat.first_name, ' ', pat.last_name) LIKE ?)";
        $like = '%' . $search . '%';
        $types .= 'sssss';
        array_push($params, $like, $like, $like, $like, $like);
    }
    $sql .= ' ORDER BY a.logged_at DESC, a.audit_id DESC LIMIT 500';

    $stmt = $conn->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();
}

$current_page = 'audit-trail';

// Query-string helper for building tab/filter links that preserve the
// other current filters - same idea as reports.php's own link building.
function auditTrailUrl($overrides = [])
{
    global $tab, $fromDate, $toDate, $search;
    $params = array_merge([
        'tab' => $tab,
        'from' => $fromDate,
        'to' => $toDate,
        'search' => $search,
    ], $overrides);
    $params = array_filter($params, static fn($v) => $v !== '');
    return 'audit-trail.php' . (empty($params) ? '' : '?' . http_build_query($params));
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit Trail - GabayMed</title>
    <link rel="stylesheet" href="../assets/css/doctor-dashboard.css">
    <link rel="stylesheet" href="../assets/css/pharmacist-dashboard.css">
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
    </style>
</head>

<body>
    <div class="app-shell">
        <?php include 'includes/sidebar.php'; ?>
        <main class="main-content">
            <header class="page-header">
                <div>
                    <h1>Audit Trail</h1>
                    <p class="page-subtitle">Operational activity across inventory, medicines, prescribing, dispensing, and reports.</p>
                </div>
            </header>

            <nav class="rp-tabs">
                <a class="rp-tab <?php echo $tab === 'all' ? 'is-active' : ''; ?>" href="<?php echo htmlspecialchars(auditTrailUrl(['tab' => 'all'])); ?>">All Activity</a>
                <?php foreach ($allowedModules as $key => $label): ?>
                    <a class="rp-tab <?php echo $tab === $key ? 'is-active' : ''; ?>" href="<?php echo htmlspecialchars(auditTrailUrl(['tab' => $key])); ?>"><?php echo htmlspecialchars($label); ?></a>
                <?php endforeach; ?>
            </nav>

            <section class="card">
                <form method="get" class="toolbar-filters audit-toolbar">
                    <input type="hidden" name="tab" value="<?php echo htmlspecialchars($tab); ?>">
                    <input class="filter-select" type="search" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="<?php echo $tab === 'dispensing' ? 'Search medicine, staff, or patient' : 'Search user, patient, action, or details'; ?>">
                    <label style="display: inline-flex; align-items: center; gap: 6px; font-size: 13.5px; color: var(--text-muted);">
                        From
                        <input class="filter-select" type="date" name="from" value="<?php echo htmlspecialchars($fromDate); ?>" aria-label="From date">
                    </label>
                    <label style="display: inline-flex; align-items: center; gap: 6px; font-size: 13.5px; color: var(--text-muted);">
                        To
                        <input class="filter-select" type="date" name="to" value="<?php echo htmlspecialchars($toDate); ?>" aria-label="To date">
                    </label>
                    <button class="btn btn-secondary btn-sm" type="submit">Filter</button>
                    <?php if ($fromDate !== '' || $toDate !== '' || $search !== ''): ?><a class="btn btn-secondary btn-sm" href="<?php echo htmlspecialchars(auditTrailUrl(['from' => '', 'to' => '', 'search' => ''])); ?>">Clear</a><?php endif; ?>
                </form>
            </section>

            <?php if ($tab === 'dispensing'): ?>
                <section class="card">
                    <div class="card-header">
                        <div>
                            <h2>Dispensing Activity</h2><span class="card-subtitle"><?php echo count($dispenseRows); ?> entr<?php echo count($dispenseRows) === 1 ? 'y' : 'ies'; ?>, newest first</span>
                        </div>
                    </div>
                    <?php if (empty($dispenseRows)): ?>
                        <div class="empty-state">
                            <p>No dispense records match the current filters.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table class="data-table audit-table">
                                <thead>
                                    <tr>
                                        <th>Date / Time</th>
                                        <th>Medicine</th>
                                        <th>Quantity</th>
                                        <th>Patient</th>
                                        <th>Dispensed By</th>
                                        <th>Source</th>
                                        <th>FEFO Batch</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($dispenseRows as $row): ?>
                                        <?php
                                        if ($row['prescription_id'] !== null) {
                                            $source = 'Prescription';
                                        } elseif ($row['confinement_discharge_medication_id'] !== null) {
                                            $source = 'Discharge Meds';
                                        } else {
                                            $source = 'Legacy';
                                        }
                                        $patientName = trim(($row['patient_first'] ?? '') . ' ' . ($row['patient_last'] ?? ''));
                                        ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($row['scanned_at']))); ?></td>
                                            <td><?php echo htmlspecialchars($row['medicine']); ?></td>
                                            <td><?php echo (int) $row['quantity']; ?> <?php echo htmlspecialchars($row['unit']); ?><?php echo (int) $row['quantity'] === 1 ? '' : 's'; ?></td>
                                            <td><?php echo $patientName !== '' ? htmlspecialchars($patientName) : '—'; ?></td>
                                            <td><?php echo htmlspecialchars($row['staff_name']); ?></td>
                                            <td><?php echo htmlspecialchars($source); ?></td>
                                            <td>
                                                <?php if ($row['batch_id'] !== null): ?>
                                                    <?php echo htmlspecialchars($row['batch_no'] ?: ('BATCH-' . $row['batch_id'])); ?>
                                                    <?php if ($row['expiry_date']): ?>
                                                        <small>(expires <?php echo htmlspecialchars(date('M j, Y', strtotime($row['expiry_date']))); ?>)</small>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    Not recorded
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </section>
            <?php else: ?>
                <section class="card">
                    <div class="card-header">
                        <div>
                            <h2>Activity Log</h2><span class="card-subtitle"><?php echo count($rows); ?> entr<?php echo count($rows) === 1 ? 'y' : 'ies'; ?>, newest first</span>
                        </div>
                    </div>
                    <?php if (empty($rows)): ?>
                        <div class="empty-state">
                            <p>No audit entries match the current filters.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table class="data-table audit-table">
                                <thead>
                                    <tr>
                                        <th>Date &amp; Time</th>
                                        <th>User</th>
                                        <th>Action</th>
                                        <?php if ($tab === 'all'): ?><th>Module</th><?php endif; ?>
                                        <th>Details</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($rows as $row): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($row['logged_at']))); ?></td>
                                            <td><?php echo htmlspecialchars($row['user_name'] ?: ('User #' . $row['user_id'])); ?><small><?php echo htmlspecialchars(ucfirst($row['user_role'])); ?></small></td>
                                            <td><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $row['action']))); ?></td>
                                            <?php if ($tab === 'all'): ?>
                                                <td><span class="audit-module-pill"><?php echo htmlspecialchars($allowedModules[$row['module']] ?? $row['module']); ?></span></td>
                                            <?php endif; ?>
                                            <td><?php echo htmlspecialchars($row['details'] ?? ''); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
        </main>
    </div>
</body>

</html>