<?php
// pharmacist/backup-restore.php
// Pharmacist-scoped Backup & Archive — the OMCDH reference screens'
// "Backup & Restore" module, adapted to this app: scoped to pharmacy
// data only (not a system-wide action, and not an Admin-only tool — see
// gabaymed area notes on why this moved to Pharmacist).
//
// "Create Backup Now" downloads a JSON snapshot (backup-export.php) of
// whichever scope is picked — no server-side storage, same
// generate-on-request approach report-export.php already uses for
// report downloads.
//
// "Archive Selected Data" moves the selected data out of active use —
// it is NOT deleted. backup-restore-actions.php copies every affected
// row into an archived_* table (grouped under one archive run) before
// clearing the live tables, so nothing is ever permanently lost. Any
// run can be restored in full from Reports & Archive's Archive tab
// (reports.php?tab=archive). Still requires the pharmacist's own current
// password (re-verified against users.password) plus an in-page confirm
// modal, since it's still a significant bulk action even though it's
// reversible.

require_once '../includes/auth_guard.php';
require_role('pharmacist');
require_once '../config/db.php';
require_once '../includes/csrf.php';

$scopeOptions = [
    'inventory_counts' => [
        'title' => 'Inventory Counts Only',
        'desc' => 'Archive all count batches, staff submissions, and confirmation history.',
    ],
    'reports' => [
        'title' => 'Reports & Exports Only',
        'desc' => 'Archive all saved Final Confirmation report snapshots and dispensing log history.',
    ],
    'medicine_batches' => [
        'title' => 'Medicine Batches Only',
        'desc' => 'Archive all recorded stock batches and reset current stock to zero. The medicine catalog itself is kept.',
    ],
    'all' => [
        'title' => 'All Pharmacy Data',
        'desc' => 'Archive inventory counts, reports, and medicine batches together.',
    ],
];

$current_page = 'backup-restore';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Backup &amp; Archive - GabayMed</title>
    <link rel="stylesheet" href="../assets/css/doctor-dashboard.css">
    <link rel="stylesheet" href="../assets/css/pharmacist-dashboard.css">
    <link rel="stylesheet" href="../assets/css/pharmacist-backup-restore.css">
</head>

<body>
    <div class="app-shell">
        <?php include 'includes/sidebar.php'; ?>
        <main class="main-content">
            <header class="page-header">
                <div>
                    <h1>Backup &amp; Archive</h1>
                    <p class="page-subtitle">Manage backups of your pharmacy data, and archive data when needed. Archived data can be restored anytime from Reports &amp; Archive.</p>
                </div>
            </header>

            <section class="card">
                <div class="card-header">
                    <div>
                        <h2>Create Backup</h2>
                        <span class="card-subtitle">Download a snapshot of pharmacy data before archiving anything.</span>
                    </div>
                </div>
                <div class="br-backup-list">
                    <?php foreach ($scopeOptions as $key => $opt): ?>
                        <a class="btn btn-secondary btn-sm" href="backup-export.php?scope=<?php echo urlencode($key); ?>">
                            Download <?php echo htmlspecialchars($opt['title']); ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="card">
                <div class="card-header">
                    <div>
                        <h2>Archive Selected Data</h2>
                        <span class="card-subtitle">Moves the selected data out of active use. Nothing is deleted — it can be restored anytime from the Archive tab.</span>
                    </div>
                </div>

                <div class="br-warning">
                    <svg class="br-warning-icon" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"></path>
                        <line x1="12" y1="9" x2="12" y2="13"></line>
                        <line x1="12" y1="17" x2="12.01" y2="17"></line>
                    </svg>
                    <div class="br-warning-text">
                        <strong>Please choose the data you want to archive.</strong>
                        <p>Archived data is hidden from normal views but not deleted — download a backup too if you'd like an offline copy, and restore anytime from Reports &amp; Archive.</p>
                    </div>
                </div>

                <form id="resetForm">
                    <?= csrf_field() ?>
                    <div class="br-scope-grid" id="scopeGrid">
                        <?php foreach ($scopeOptions as $key => $opt): ?>
                            <label class="br-scope-card" data-scope="<?php echo htmlspecialchars($key); ?>">
                                <input type="radio" name="scope" value="<?php echo htmlspecialchars($key); ?>">
                                <span class="br-scope-icon">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <polyline points="21 8 21 21 3 21 3 8"></polyline>
                                        <rect x="1" y="3" width="22" height="5"></rect>
                                        <line x1="10" y1="12" x2="14" y2="12"></line>
                                    </svg>
                                </span>
                                <div class="br-scope-title"><?php echo htmlspecialchars($opt['title']); ?></div>
                                <div class="br-scope-desc"><?php echo htmlspecialchars($opt['desc']); ?></div>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <div class="form-group br-password-row">
                        <label class="form-label" for="resetPassword">Your Password</label>
                        <input class="form-input" type="password" id="resetPassword" name="password" placeholder="Enter your password" autocomplete="current-password">
                    </div>

                    <div class="br-actions">
                        <button class="btn btn-danger" type="submit" id="resetSubmitBtn" disabled>Archive Selected Data</button>
                        <span class="br-inline-error" id="resetError" hidden></span>
                    </div>
                </form>

                <div class="br-reminder">
                    <span>Archiving is reversible, but always ensure you have a recent backup too.</span>
                </div>
            </section>

            <footer class="page-footer">
                <p>&copy; <?php echo date('Y'); ?> GabayMed Hospital Management System</p>
            </footer>
        </main>
    </div>

    <!-- Confirm-archive modal -->
    <div class="modal-backdrop" id="confirmModal">
        <div class="modal-box br-modal-box">
            <h3>Confirm archiving</h3>
            <p id="confirmModalText">This will move the selected data to the Archive. It will no longer appear in normal views, but can be restored anytime.</p>
            <div class="br-modal-actions">
                <button class="btn btn-secondary" type="button" id="cancelResetBtn">Cancel</button>
                <button class="btn btn-danger" type="button" id="confirmResetBtn">Yes, Archive Data</button>
            </div>
        </div>
    </div>

    <script>
        (function() {
            const scopeCards = Array.from(document.querySelectorAll('.br-scope-card'));
            const submitBtn = document.getElementById('resetSubmitBtn');
            const passwordInput = document.getElementById('resetPassword');
            const form = document.getElementById('resetForm');
            const errorBox = document.getElementById('resetError');
            const modal = document.getElementById('confirmModal');
            const modalText = document.getElementById('confirmModalText');
            const cancelBtn = document.getElementById('cancelResetBtn');
            const confirmBtn = document.getElementById('confirmResetBtn');

            const scopeTitles = <?php
                                $titles = [];
                                foreach ($scopeOptions as $key => $opt) $titles[$key] = $opt['title'];
                                echo json_encode($titles);
                                ?>;

            function selectedScope() {
                const checked = form.querySelector('input[name="scope"]:checked');
                return checked ? checked.value : '';
            }

            function syncState() {
                scopeCards.forEach(function(card) {
                    const input = card.querySelector('input[type="radio"]');
                    card.classList.toggle('is-selected', !!input && input.checked);
                });
                submitBtn.disabled = !selectedScope() || passwordInput.value.trim() === '';
            }

            scopeCards.forEach(function(card) {
                card.addEventListener('click', function() {
                    const input = card.querySelector('input[type="radio"]');
                    input.checked = true;
                    syncState();
                });
            });
            passwordInput.addEventListener('input', syncState);
            syncState();

            function openModal() {
                const scope = selectedScope();
                modalText.textContent = 'This will move to the Archive: ' + (scopeTitles[scope] || 'the selected data') + '. It will no longer appear in normal views, but can be restored anytime from the Archive tab.';
                document.body.classList.add('modal-open');
                modal.classList.add('active');
            }

            function closeModal() {
                document.body.classList.remove('modal-open');
                modal.classList.remove('active');
            }

            form.addEventListener('submit', function(event) {
                event.preventDefault();
                errorBox.hidden = true;
                errorBox.textContent = '';
                openModal();
            });

            cancelBtn.addEventListener('click', closeModal);
            modal.addEventListener('click', function(event) {
                if (event.target === modal) closeModal();
            });

            confirmBtn.addEventListener('click', async function() {
                confirmBtn.disabled = true;
                const data = new FormData(form);
                data.append('action', 'reset_data');

                try {
                    const response = await fetch('backup-restore-actions.php', {
                        method: 'POST',
                        body: data,
                    });
                    const result = await response.json();
                    if (!response.ok || !result.success) {
                        throw new Error(result.error || 'Could not archive data.');
                    }
                    closeModal();
                    window.location.reload();
                } catch (err) {
                    closeModal();
                    errorBox.textContent = err.message || 'Could not archive data.';
                    errorBox.hidden = false;
                    confirmBtn.disabled = false;
                }
            });
        })();
    </script>
</body>

</html>