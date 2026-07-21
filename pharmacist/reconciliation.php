<?php
// pharmacist/reconciliation.php
// Module 3.7 — Reconciliation Entry.
//
// REAL BACKEND (2026-07-20): inventory_reconciliation and medicine_exit_log
// both exist for real now (the latter wired up in exit-actions.php) — see
// reconciliation-actions.php. "Calculate & Compare" and "Submit
// Reconciliation" both hit that endpoint; Submit recomputes system_count
// itself server-side rather than trusting whatever Calculate showed on
// screen, same principle as not trusting a client-supplied price.
//
// Per 3.7: pharmacist enters a physical count for one medicine over a
// period, "Calculate & Compare" checks it against the system's exit-scan
// total for that period (SUM of medicine_exit_log.units_deducted, not a
// raw row COUNT(*) — a COUNT would undercount whenever a single scan
// released more than one box) and shows Match/Mismatch, then a separate
// submit step finalizes it. Per-medicine granularity (not a bulk/all-medicines
// count) even though it's more data entry, since that's the actual point
// of the transparency feature. Submitted entries become visible in
// Admin's Reconciliation Review (4.5).
//
// "Approve Adjustment" stays a UI preview, on purpose, not an oversight:
// adjustment_reason has no column to persist to, and more importantly,
// this page's own comment says submitted entries flow to Admin's
// Reconciliation Review (4.5) — actually adjusting current_stock off a
// mismatch reads like that review step's call to make, not something a
// pharmacist should be able to trigger on their own submission. Revisit
// once 4.5 exists.

require_once '../includes/auth_guard.php';
require_role('pharmacist');
require_once '../config/db.php';
require_once '../includes/csrf.php';

$medicines = [];
$result = $conn->query("SELECT medicine_id, name FROM inventory_medicines ORDER BY name ASC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $medicines[] = ["id" => (int) $row['medicine_id'], "name" => $row['name']];
    }
}

$recentReconciliations = [];
$result = $conn->query(
    "SELECT ir.reconciliation_id, im.name AS medicine_name, ir.period_start,
            ir.period_end, ir.system_count, ir.actual_count, ir.status, ir.submitted_at
     FROM inventory_reconciliation ir
     JOIN inventory_medicines im ON im.medicine_id = ir.medicine_id
     ORDER BY ir.submitted_at DESC
     LIMIT 10"
);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $start = new DateTime($row['period_start']);
        $end = new DateTime($row['period_end']);
        $recentReconciliations[] = [
            "medicine" => $row['medicine_name'],
            "period"   => $start->format('M j') . ' &ndash; ' . $end->format('M j, Y'),
            "system"   => (int) $row['system_count'],
            "actual"   => (int) $row['actual_count'],
            "status"   => $row['status'],
            "date"     => (new DateTime($row['submitted_at']))->format('M j, Y'),
        ];
    }
}

$current_page = 'reconciliation';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reconciliation Entry - GabayMed</title>
    <link rel="stylesheet" href="../assets/css/doctor-dashboard.css">
    <link rel="stylesheet" href="../assets/css/pharmacist-dashboard.css">
</head>

<body>

    <div class="app-shell">

        <?php include 'includes/sidebar.php'; ?>

        <!-- Main content -->
        <main class="main-content">

            <header class="page-header">
                <div>
                    <h1>Reconciliation Entry</h1>
                    <p class="page-subtitle">Compare a physical count against the system's exit-scan record, per medicine.</p>
                </div>
            </header>

            <section class="card recon-form-card">
                <div class="card-header">
                    <h2>New Reconciliation</h2>
                    <span class="card-subtitle">One medicine per entry</span>
                </div>

                <form id="reconForm">
                    <?= csrf_field() ?>
                    <div class="recon-form-grid">
                        <div class="dr-field">
                            <label for="reconMedicine">Medicine</label>
                            <select id="reconMedicine" required>
                                <option value="">Select medicine&hellip;</option>
                                <?php foreach ($medicines as $med): ?>
                                    <option value="<?php echo (int) $med['id']; ?>"><?php echo htmlspecialchars($med['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="dr-field">
                            <label for="periodStart">Period Start</label>
                            <input type="date" id="periodStart" required>
                        </div>
                        <div class="dr-field">
                            <label for="periodEnd">Period End</label>
                            <input type="date" id="periodEnd" required>
                        </div>
                        <div class="dr-field">
                            <label for="actualCount">Physical Count</label>
                            <input type="number" id="actualCount" min="0" step="1" placeholder="Units physically counted" required>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-secondary" id="calculateBtn">Calculate &amp; Compare</button>
                </form>

                <!-- Comparison result — hidden until Calculate & Compare runs -->
                <div class="recon-result" id="reconResult" hidden>
                    <div class="recon-result-row">
                        <div class="summary-item">
                            <span class="summary-label">System Stock (exit scans)</span>
                            <span class="summary-value" id="resultSystemCount">&mdash;</span>
                        </div>
                        <div class="summary-item">
                            <span class="summary-label">Physical Count</span>
                            <span class="summary-value" id="resultActualCount">&mdash;</span>
                        </div>
                        <div class="summary-item">
                            <span class="summary-label">Difference</span>
                            <span class="summary-value" id="resultDifference">&mdash;</span>
                        </div>
                    </div>
                    <span class="status-pill" id="resultStatusPill">&mdash;</span>

                    <!-- UI ONLY: no adjustment_reason column exists on
                         inventory_reconciliation — not read by Submit
                         Reconciliation below. -->
                    <div class="dr-field dr-field-spaced">
                        <label for="adjustmentReason">Adjustment Reason</label>
                        <textarea id="adjustmentReason" rows="2" placeholder="e.g. Damaged stock, counting error, misplaced units"></textarea>
                    </div>

                    <div class="recon-submit-row">
                        <button type="button" class="btn btn-primary" id="submitReconBtn">Submit Reconciliation</button>
                        <span class="recon-submit-hint">Visible to Admin under Reconciliation Review once submitted.</span>
                    </div>

                    <div class="recon-adjustment-actions">
                        <button type="button" class="btn btn-approve btn-sm" id="approveAdjustmentBtn">Approve Adjustment</button>
                        <span class="recon-submit-hint">Marks the counted difference as an approved stock adjustment (UI preview &mdash; not yet wired to a backend).</span>
                    </div>
                </div>
            </section>

            <section class="card">
                <div class="card-header">
                    <h2>Recent Reconciliations</h2>
                </div>
                <?php if (empty($recentReconciliations)): ?>
                    <div class="empty-state">
                        <p>No reconciliations submitted yet</p>
                    </div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="queue-table" id="reconHistoryTable">
                            <thead>
                                <tr>
                                    <th>Medicine</th>
                                    <th>Period</th>
                                    <th>System Count</th>
                                    <th>Actual Count</th>
                                    <th>Result</th>
                                    <th>Submitted</th>
                                </tr>
                            </thead>
                            <tbody id="reconHistoryBody">
                                <?php foreach ($recentReconciliations as $r): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($r['medicine']); ?></td>
                                        <td><?php echo $r['period']; ?></td>
                                        <td><?php echo (int) $r['system']; ?></td>
                                        <td><?php echo (int) $r['actual']; ?></td>
                                        <td><span class="status-pill status-<?php echo $r['status']; ?>"><?php echo $r['status'] === 'match' ? 'Match' : 'Mismatch'; ?></span></td>
                                        <td><?php echo htmlspecialchars($r['date']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>

            <footer class="page-footer">
                <p>&copy; <?php echo date("Y"); ?> GabayMed Hospital Management System</p>
            </footer>

        </main>
    </div>

    <div class="scan-toast-host" id="reconToastHost"></div>

    <script>
        // REAL BACKEND (2026-07-20): both actions post to
        // reconciliation-actions.php — calculate_compare for the live
        // preview, submit_reconciliation to finalize. See that file for
        // why submit recomputes system_count itself instead of trusting
        // whatever calculate_compare returned earlier in the session.
        const reconForm = document.getElementById('reconForm');
        const reconResult = document.getElementById('reconResult');
        const submitBtn = document.getElementById('submitReconBtn');
        const calculateBtn = document.getElementById('calculateBtn');
        let lastCompared = null;

        reconForm.addEventListener('submit', function(e) {
            e.preventDefault();

            const medicineSelect = document.getElementById('reconMedicine');
            const medicineId = medicineSelect.value;
            const medicineName = medicineSelect.options[medicineSelect.selectedIndex].text;
            const periodStart = document.getElementById('periodStart').value;
            const periodEnd = document.getElementById('periodEnd').value;
            const actual = parseInt(document.getElementById('actualCount').value, 10);

            if (!medicineId || !periodStart || !periodEnd || isNaN(actual)) return;

            const csrfToken = reconForm.querySelector('[name="csrf_token"]').value;
            calculateBtn.disabled = true;
            calculateBtn.textContent = 'Calculating\u2026';

            const body = new URLSearchParams({
                action: 'calculate_compare',
                csrf_token: csrfToken,
                medicine_id: medicineId,
                period_start: periodStart,
                period_end: periodEnd,
                actual_count: actual,
            });

            fetch('reconciliation-actions.php', {
                    method: 'POST',
                    body: body,
                })
                .then(res => res.json())
                .then(data => {
                    calculateBtn.disabled = false;
                    calculateBtn.textContent = 'Calculate & Compare';

                    if (!data.success) {
                        showToast(data.error || 'Something went wrong. Please try again.');
                        return;
                    }

                    document.getElementById('resultSystemCount').textContent = data.system_count;
                    document.getElementById('resultActualCount').textContent = actual;
                    document.getElementById('resultDifference').textContent = (data.difference > 0 ? '+' : '') + data.difference;

                    const pill = document.getElementById('resultStatusPill');
                    pill.textContent = data.is_match ? 'Match' : 'Mismatch';
                    pill.className = 'status-pill ' + (data.is_match ? 'status-match' : 'status-mismatch');

                    reconResult.hidden = false;
                    lastCompared = {
                        medicineId,
                        medicineName,
                        periodStart,
                        periodEnd,
                        actual,
                    };
                })
                .catch(() => {
                    calculateBtn.disabled = false;
                    calculateBtn.textContent = 'Calculate & Compare';
                    showToast('Could not reach the server. Please check your connection and try again.');
                });
        });

        submitBtn.addEventListener('click', function() {
            if (!lastCompared) return;

            const csrfToken = reconForm.querySelector('[name="csrf_token"]').value;
            submitBtn.disabled = true;
            submitBtn.textContent = 'Submitting\u2026';

            const body = new URLSearchParams({
                action: 'submit_reconciliation',
                csrf_token: csrfToken,
                medicine_id: lastCompared.medicineId,
                period_start: lastCompared.periodStart,
                period_end: lastCompared.periodEnd,
                actual_count: lastCompared.actual,
            });

            fetch('reconciliation-actions.php', {
                    method: 'POST',
                    body: body,
                })
                .then(res => res.json())
                .then(data => {
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Submit Reconciliation';

                    if (!data.success) {
                        showToast(data.error || 'Something went wrong. Please try again.');
                        return;
                    }

                    const tbody = document.getElementById('reconHistoryBody');
                    const row = document.createElement('tr');
                    const periodLabel = formatPeriod(lastCompared.periodStart, lastCompared.periodEnd);
                    row.innerHTML = `
                        <td>${lastCompared.medicineName}</td>
                        <td>${periodLabel}</td>
                        <td>${data.system_count}</td>
                        <td>${lastCompared.actual}</td>
                        <td><span class="status-pill status-${data.is_match ? 'match' : 'mismatch'}">${data.is_match ? 'Match' : 'Mismatch'}</span></td>
                        <td>Just now</td>
                    `;
                    tbody.insertBefore(row, tbody.firstChild);

                    showToast(`\u2713 Reconciliation submitted for ${lastCompared.medicineName}`);

                    reconForm.reset();
                    reconResult.hidden = true;
                    lastCompared = null;
                })
                .catch(() => {
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Submit Reconciliation';
                    showToast('Could not reach the server. Please check your connection and try again.');
                });
        });

        // UI ONLY (2026-07-18): no backend endpoint exists yet for approving
        // an adjustment — this just gives the button a visible response
        // (toast) so the screen reads as a working audit flow in review,
        // same spirit as this page's other demo-only interactions. Left
        // this way on purpose even in this real-backend pass — see the
        // scope-decision note at the top of this file.
        const approveAdjustmentBtn = document.getElementById('approveAdjustmentBtn');
        approveAdjustmentBtn.addEventListener('click', function() {
            if (!lastCompared) {
                showToast('Run Calculate & Compare first, then approve the adjustment.');
                return;
            }
            showToast(`\u2713 Adjustment approved for ${lastCompared.medicineName} (preview only \u2014 pending backend integration)`);
        });

        function formatPeriod(start, end) {
            const opts = {
                month: 'short',
                day: 'numeric',
                year: 'numeric'
            };
            const s = new Date(start + 'T00:00:00').toLocaleDateString('en-US', opts);
            const e = new Date(end + 'T00:00:00').toLocaleDateString('en-US', opts);
            return `${s} \u2013 ${e}`;
        }

        function showToast(message) {
            const host = document.getElementById('reconToastHost');
            const toast = document.createElement('div');
            toast.className = 'scan-toast';
            toast.textContent = message;
            host.appendChild(toast);
            requestAnimationFrame(() => toast.classList.add('scan-toast-show'));
            setTimeout(() => {
                toast.classList.remove('scan-toast-show');
                setTimeout(() => toast.remove(), 250);
            }, 2400);
        }
    </script>
</body>

</html>