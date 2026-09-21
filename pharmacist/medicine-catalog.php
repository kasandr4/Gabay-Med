<?php
// pharmacist/medicine-catalog.php


require_once '../includes/auth_guard.php';
require_role('pharmacist');
require_once '../config/db.php';
require_once '../includes/csrf.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$sourceLabels = [
    'maip' => 'MAIP',
    'philhealth' => 'PhilHealth',
    'pho' => 'PHO',
    'purchase_order' => 'Purchase Order',
    'donated' => 'Donated',
    'count_adjustment' => 'Count Adjustment',
];

// One row per batch (medicine + brand + expiry + remaining count), matching
// the layout of the physical INVENTORY STOCKS sheet: rows are grouped by
// medicine and tinted by funding source instead of showing a single
// aggregated stock figure per medicine.
//
// CATEGORY RETIRED: the pharmacy only manages medicine stock (no
// equipment/supplies in the real inventory), and clinical category
// (Antibiotic/Analgesic/etc.) was never functionally used beyond that
// equipment/supplies exclusion — see the migration removing
// medicine_categories / medicine_names.category. Unit is still tracked
// (medicine_units untouched), so the unit Legacy flag below still applies.
$batches = [];
$result = $conn->query(
    "SELECT im.medicine_id, im.name, im.strength, im.unit,
            mn.name AS generic_name, mu.unit_id,
            mb.batch_id, mb.source, mb.brand, mb.expiry_date, mb.units_remaining,
            mb.unit_price, mb.selling_price
     FROM medicine_batches mb
     JOIN inventory_medicines im ON im.medicine_id = mb.medicine_id
     JOIN medicine_names mn ON mn.generic_id = im.generic_id
     LEFT JOIN medicine_units mu ON LOWER(mu.name) = LOWER(im.unit)
     ORDER BY mn.name ASC, im.strength ASC, mb.expiry_date ASC"
);
if ($result) {
    $batches = $result->fetch_all(MYSQLI_ASSOC);
}

$units = [];
$unitResult = $conn->query("SELECT unit_id, name FROM medicine_units ORDER BY name ASC");
if ($unitResult) {
    $units = $unitResult->fetch_all(MYSQLI_ASSOC);
}

$current_page = 'medicine-catalog';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Medicine Catalog - GabayMed</title>
    <link rel="stylesheet" href="../assets/css/doctor-dashboard.css">
    <link rel="stylesheet" href="../assets/css/admin-dashboard.css">
    <link rel="stylesheet" href="../assets/css/medicine-catalog.css">
</head>

<body>
    <input type="hidden" id="mcCsrfToken" value="<?php echo htmlspecialchars($csrfToken); ?>">
    <div class="app-shell">
        <?php include 'includes/sidebar.php'; ?>
        <main class="main-content">
            <header class="page-header">
                <div>
                    <h1>Medicine Catalog</h1>
                    <p class="page-subtitle">Browse current stock.</p>
                </div>
                <div class="header-actions">
                    <button class="btn btn-primary" type="button" id="openListsModal">Manage Units</button>
                </div>
            </header>

            <section class="card">
                <div class="mc-toolbar">
                    <div>
                        <h2>All Medicines</h2>
                        <span class="card-subtitle"><?php echo count($batches); ?> batch<?php echo count($batches) === 1 ? '' : 'es'; ?></span>
                    </div>
                    <input class="mc-search" id="medicineCatalogSearch" type="search" placeholder="Search medicine or brand">
                </div>
                <div class="mc-legend">
                    <?php foreach ($sourceLabels as $sourceKey => $sourceLabel): ?>
                        <span class="mc-legend-item"><span class="mc-legend-swatch mc-source-<?php echo htmlspecialchars($sourceKey); ?>"></span><?php echo htmlspecialchars($sourceLabel); ?></span>
                    <?php endforeach; ?>
                </div>
                <?php if (empty($batches)): ?>
                    <div class="empty-state">
                        <p>No medicines in the catalog yet.</p>
                    </div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="data-table" id="medicineCatalogTable">
                            <thead>
                                <tr>
                                    <th>Medicine Name</th>
                                    <th>Brand</th>
                                    <th>Unit</th>
                                    <th>Expiration Date</th>
                                    <th>Unit Price</th>
                                    <th>Selling Price</th>
                                    <th>Final Count</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($batches as $batch): ?>
                                    <tr class="mc-row-<?php echo htmlspecialchars($batch['source']); ?>">
                                        <td><strong><?php echo htmlspecialchars($batch['name']); ?></strong><?php if ($batch['generic_name'] !== $batch['name']): ?><span class="mc-muted"><?php echo htmlspecialchars($batch['generic_name']); ?></span><?php endif; ?></td>
                                        <td><?php echo htmlspecialchars($batch['brand'] ?: '—'); ?></td>
                                        <td><?php echo htmlspecialchars($batch['unit']); ?><?php if (!$batch['unit_id']): ?><span class="mc-legacy-flag">Legacy</span><?php endif; ?></td>
                                        <td><?php echo $batch['expiry_date'] ? htmlspecialchars(date('M j, Y', strtotime($batch['expiry_date']))) : '—'; ?></td>
                                        <td><?php echo $batch['unit_price'] !== null ? '₱' . number_format((float) $batch['unit_price'], 2) : '—'; ?></td>
                                        <td><?php echo $batch['selling_price'] !== null ? '₱' . number_format((float) $batch['selling_price'], 2) : '—'; ?></td>
                                        <td><?php echo (int) $batch['units_remaining']; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        </main>
    </div>

    <div class="mc-modal-backdrop" id="listsModal" aria-hidden="true">
        <div class="mc-modal" role="dialog" aria-modal="true" aria-labelledby="listsModalTitle">
            <div class="mc-modal-header">
                <h3 id="listsModalTitle">Manage Units</h3>
                <button class="mc-modal-close" type="button" id="closeListsModal" aria-label="Close">&times;</button>
            </div>
            <section class="mc-reference-grid">
                <div class="card mc-reference-card">
                    <div class="card-header">
                        <div>
                            <h2>Units</h2><span class="card-subtitle">Controlled list for new medicines</span>
                        </div>
                    </div>
                    <form class="mc-reference-form" data-reference-form>
                        <input type="hidden" name="action" value="add_unit">
                        <input name="name" maxlength="30" placeholder="Add unit" required>
                        <button class="btn btn-secondary btn-sm" type="submit">Add</button>
                    </form>
                    <ul class="mc-reference-list">
                        <?php foreach ($units as $unit): ?>
                            <li><span><?php echo htmlspecialchars($unit['name']); ?></span><button class="mc-remove-reference" type="button" data-action="remove_unit" data-id="<?php echo (int) $unit['unit_id']; ?>" aria-label="Remove unit">&times;</button></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </section>
        </div>
    </div>

    <script>
        (function() {
            'use strict';
            const csrfToken = document.getElementById('mcCsrfToken').value;
            const listsModal = document.getElementById('listsModal');

            function closeListsModal() {
                listsModal.classList.remove('active');
                listsModal.setAttribute('aria-hidden', 'true');
                document.body.classList.remove('modal-open');
            }

            function openListsModal() {
                listsModal.classList.add('active');
                listsModal.setAttribute('aria-hidden', 'false');
                document.body.classList.add('modal-open');
            }

            document.getElementById('openListsModal').addEventListener('click', openListsModal);
            document.getElementById('closeListsModal').addEventListener('click', closeListsModal);
            listsModal.addEventListener('click', function(event) {
                if (event.target === listsModal) closeListsModal();
            });
            document.addEventListener('keydown', function(event) {
                if (event.key === 'Escape' && listsModal.classList.contains('active')) closeListsModal();
            });

            async function postCatalogAction(data) {
                data.append('csrf_token', csrfToken);
                const response = await fetch('medicine-catalog-action.php', {
                    method: 'POST',
                    body: data
                });
                const result = await response.json();
                if (!response.ok || !result.success) {
                    throw new Error(result.error || 'Could not update the catalog.');
                }
                window.location.reload();
            }

            document.querySelectorAll('[data-reference-form]').forEach(function(referenceForm) {
                referenceForm.addEventListener('submit', function(event) {
                    event.preventDefault();
                    postCatalogAction(new FormData(referenceForm)).catch(function(requestError) {
                        alert(requestError.message || 'Could not update the catalog.');
                    });
                });
            });

            document.querySelectorAll('.mc-remove-reference').forEach(function(button) {
                button.addEventListener('click', function() {
                    if (!window.confirm('Remove this entry from the controlled list?')) return;
                    const data = new FormData();
                    data.append('action', button.dataset.action);
                    data.append('id', button.dataset.id);
                    postCatalogAction(data).catch(function(requestError) {
                        alert(requestError.message || 'Could not remove that entry.');
                    });
                });
            });

            const search = document.getElementById('medicineCatalogSearch');
            const rows = document.querySelectorAll('#medicineCatalogTable tbody tr');
            search.addEventListener('input', function() {
                const query = search.value.trim().toLowerCase();
                rows.forEach(function(row) {
                    row.hidden = !row.textContent.toLowerCase().includes(query);
                });
            });
        })();
    </script>
</body>

</html>