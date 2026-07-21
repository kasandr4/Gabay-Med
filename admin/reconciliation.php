<?php
// admin/reconciliation.php
// Module 4.5 — Reconciliation Review.
//
// REAL BACKEND (2026-07-20), and a substantial simplification from the
// version this replaced. That version was a much richer mockup —
// Department (Pharmacy/Central Supply/Emergency Pharmacy/Warehouse),
// Type (Inventory Count/Delivery Receiving/Stock Adjustment/Monthly
// Inventory), formatted IDs like REC-2026-0715-001, Priority badges, a
// multi-item drawer with fake PO/delivery/document references and
// per-line prices, six analytics chart cards, a fabricated audit log
// with Finance Desk entries — none of which has anywhere to come from.
// The real inventory_reconciliation table (see
// inventory_reconciliation_review_migration.sql for the review-tracking
// columns added to it) is just: one medicine, one period, a system count,
// a physical count, and who submitted it. Per your call, this page was
// cut down to match that instead of inventing schema to justify the
// mockup's scope. Gone entirely: the Discrepancies priority queue,
// Approval History timeline, monthly-summary-grid, the six chart cards,
// Audit Log, the loading-skeleton demo, and the Recent Activity sidebar.
// Kept: stat cards (now real counts), the main records table, and
// Approve Adjustment / Return for Revision — see
// reconciliation-review-actions.php for what those actually do.
//
// assets/js/reconciliation.js and assets/css/reconciliation.css are no
// longer used by this page (everything needed is now inline below and in
// the trimmed table/stat-card rules already in reconciliation.css) —
// left on disk rather than deleted since nothing else references them,
// but they're dead weight for this page now.

require_once '../includes/auth_guard.php';
require_role('admin');
require_once '../config/db.php';
require_once '../includes/csrf.php';

$reconciliations = [];
$result = $conn->query(
    "SELECT ir.reconciliation_id, im.name AS medicine, ir.period_start, ir.period_end,
            ir.system_count, ir.actual_count, ir.status, ir.review_status,
            CONCAT(sub.first_name, ' ', sub.last_name) AS submitted_by_name,
            ir.submitted_at,
            CONCAT(rev.first_name, ' ', rev.last_name) AS reviewed_by_name,
            ir.reviewed_at
     FROM inventory_reconciliation ir
     JOIN inventory_medicines im ON im.medicine_id = ir.medicine_id
     JOIN users sub ON sub.user_id = ir.submitted_by
     LEFT JOIN users rev ON rev.user_id = ir.reviewed_by
     ORDER BY ir.submitted_at DESC"
);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $reconciliations[] = $row;
    }
}

$pendingCount = 0;
$approvedCount = 0;
$returnedCount = 0;
$openMismatchCount = 0;
foreach ($reconciliations as $r) {
    if ($r['review_status'] === 'pending') {
        $pendingCount++;
        if ($r['status'] === 'mismatch') $openMismatchCount++;
    } elseif ($r['review_status'] === 'approved') {
        $approvedCount++;
    } elseif ($r['review_status'] === 'revision_requested') {
        $returnedCount++;
    }
}

$reviewStatusMeta = [
    'pending'             => ['status-recon-pending', 'Pending'],
    'approved'            => ['status-recon-approved', 'Approved'],
    'revision_requested'  => ['status-recon-returned', 'Returned'],
];

$today = date("F j, Y");
$current_page = 'reconciliation';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reconciliation Review - GabayMed</title>
    <link rel="stylesheet" href="../assets/css/doctor-dashboard.css">
    <link rel="stylesheet" href="../assets/css/admin-dashboard.css">
    <link rel="stylesheet" href="../assets/css/reconciliation.css">
</head>

<body>
    <div class="app-shell">
        <?php include 'includes/sidebar.php'; ?>

        <main class="main-content">
            <header class="page-header recon-page-header">
                <div>
                    <h1>Reconciliation Review</h1>
                    <p class="page-subtitle">Admin review only. Pharmacy submits inventory reconciliation records here for verification and approval — inventory itself is managed in Pharmacy Inventory.</p>
                </div>
                <div class="header-actions recon-header-actions">
                    <span class="header-date"><?php echo htmlspecialchars($today); ?></span>
                    <button class="btn btn-secondary" type="button" id="exportPdfBtn">Export PDF</button>
                </div>
            </header>

            <section class="stats-grid recon-summary-grid">
                <div class="stat-card stat-amber recon-stat-card fade-in-card">
                    <div class="stat-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"></circle>
                            <path d="M12 6v6l4 2"></path>
                        </svg></div>
                    <div class="stat-info"><span class="stat-value"><?php echo $pendingCount; ?></span><span class="stat-label">Pending Review</span><span class="recon-stat-desc">Submitted by Pharmacy, awaiting review</span></div>
                </div>
                <div class="stat-card stat-teal recon-stat-card fade-in-card">
                    <div class="stat-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M20 6L9 17l-5-5"></path>
                        </svg></div>
                    <div class="stat-info"><span class="stat-value"><?php echo $approvedCount; ?></span><span class="stat-label">Approved</span><span class="recon-stat-desc">Verified and closed by Admin</span></div>
                </div>
                <div class="stat-card stat-blue recon-stat-card fade-in-card">
                    <div class="stat-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="1 4 1 10 7 10"></polyline>
                            <path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"></path>
                        </svg></div>
                    <div class="stat-info"><span class="stat-value"><?php echo $returnedCount; ?></span><span class="stat-label">Returned for Revision</span><span class="recon-stat-desc">Sent back to Pharmacy for a recount</span></div>
                </div>
                <div class="stat-card stat-red recon-stat-card fade-in-card">
                    <div class="stat-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
                            <path d="M12 9v4"></path>
                            <path d="M12 17h.01"></path>
                        </svg></div>
                    <div class="stat-info"><span class="stat-value"><?php echo $openMismatchCount; ?></span><span class="stat-label">Open Mismatches</span><span class="recon-stat-desc">Pending review, system vs. physical count differ</span></div>
                </div>
            </section>

            <section class="card recon-table-card">
                <div class="card-header recon-card-header">
                    <div>
                        <h2>Reconciliation Records</h2>
                        <p class="card-subtitle">Inventory reconciliation records submitted by Pharmacy, newest first.</p>
                    </div>
                </div>
                <div class="recon-toolbar">
                    <div class="search-field recon-search-field"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="11" cy="11" r="8"></circle>
                            <path d="M21 21l-4.35-4.35"></path>
                        </svg><input type="text" id="reconSearch" placeholder="Search medicine or submitted by" aria-label="Search medicine or submitted by"></div>
                    <div class="recon-filters">
                        <select class="filter-select" id="reconStatusFilter">
                            <option value="">All Statuses</option>
                            <option value="pending">Pending</option>
                            <option value="approved">Approved</option>
                            <option value="revision_requested">Returned</option>
                        </select>
                        <button class="btn btn-secondary recon-reset-btn" type="button" id="reconResetBtn">Reset Filters</button>
                    </div>
                </div>
                <?php if (empty($reconciliations)): ?>
                    <div class="empty-state">
                        <p>No reconciliation records yet</p>
                    </div>
                <?php else: ?>
                    <div class="table-wrap recon-table-wrap">
                        <table class="queue-table recon-table" id="reconTable">
                            <thead>
                                <tr>
                                    <th>Medicine</th>
                                    <th>Period</th>
                                    <th>System Count</th>
                                    <th>Actual Count</th>
                                    <th>Result</th>
                                    <th>Submitted By</th>
                                    <th>Date Submitted</th>
                                    <th>Review Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="reconTableBody">
                                <?php foreach ($reconciliations as $r):
                                    $start = new DateTime($r['period_start']);
                                    $end = new DateTime($r['period_end']);
                                    $periodLabel = $start->format('M j') . ' &ndash; ' . $end->format('M j, Y');
                                    $diff = (int) $r['actual_count'] - (int) $r['system_count'];
                                    $resultClass = $r['status'] === 'match' ? 'status-recon-matched' : 'status-recon-discrepancy';
                                    $resultLabel = $r['status'] === 'match' ? 'Match' : 'Mismatch';
                                    [$reviewClass, $reviewLabel] = $reviewStatusMeta[$r['review_status']];
                                    $searchBlob = strtolower($r['medicine'] . ' ' . $r['submitted_by_name']);
                                ?>
                                    <tr data-status="<?php echo htmlspecialchars($r['review_status']); ?>" data-search="<?php echo htmlspecialchars($searchBlob); ?>">
                                        <td><?php echo htmlspecialchars($r['medicine']); ?></td>
                                        <td><?php echo $periodLabel; ?></td>
                                        <td><?php echo (int) $r['system_count']; ?></td>
                                        <td><?php echo (int) $r['actual_count']; ?></td>
                                        <td><span class="status-pill <?php echo $resultClass; ?>"><?php echo ($diff > 0 ? '+' : '') . $diff; ?> &middot; <?php echo $resultLabel; ?></span></td>
                                        <td><?php echo htmlspecialchars($r['submitted_by_name']); ?></td>
                                        <td><?php echo (new DateTime($r['submitted_at']))->format('M j, Y'); ?></td>
                                        <td><span class="status-pill <?php echo $reviewClass; ?>"><?php echo $reviewLabel; ?></span></td>
                                        <td>
                                            <?php if ($r['review_status'] === 'pending'): ?>
                                                <div class="recon-row-actions">
                                                    <button class="btn-row-action recon-approve-btn" data-id="<?php echo (int) $r['reconciliation_id']; ?>" type="button">Approve Adjustment</button>
                                                    <button class="btn-row-action btn-row-danger recon-return-btn" data-id="<?php echo (int) $r['reconciliation_id']; ?>" type="button">Return for Revision</button>
                                                </div>
                                            <?php else: ?>
                                                <span class="recon-reviewed-note">by <?php echo htmlspecialchars($r['reviewed_by_name'] ?? '—'); ?>, <?php echo $r['reviewed_at'] ? (new DateTime($r['reviewed_at']))->format('M j, Y') : ''; ?></span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="empty-state" id="reconNoMatch" hidden>
                        <p>No records match your filters</p>
                    </div>
                <?php endif; ?>
            </section>

            <footer class="page-footer">
                <p>&copy; <?php echo date("Y"); ?> GabayMed Hospital Management System</p>
            </footer>
        </main>
    </div>

    <div class="scan-toast-host" id="reconToastHost"></div>
    <?php echo csrf_field(); ?>

    <script>
        // REAL BACKEND (2026-07-20): Approve Adjustment / Return for
        // Revision both post to reconciliation-review-actions.php. See
        // that file for what "approve" actually does to current_stock,
        // and the header comment above for why this page dropped most of
        // what the old mockup had.
        const csrfToken = document.querySelector('[name="csrf_token"]').value;
        const reconTableBody = document.getElementById('reconTableBody');
        const reconSearch = document.getElementById('reconSearch');
        const reconStatusFilter = document.getElementById('reconStatusFilter');
        const reconResetBtn = document.getElementById('reconResetBtn');
        const reconNoMatch = document.getElementById('reconNoMatch');
        const reconTable = document.getElementById('reconTable');

        function applyFilters() {
            if (!reconTableBody) return;
            const term = reconSearch.value.trim().toLowerCase();
            const status = reconStatusFilter.value;
            const rows = reconTableBody.querySelectorAll('tr');
            let visibleCount = 0;

            rows.forEach(row => {
                const matchesText = !term || row.dataset.search.includes(term);
                const matchesStatus = !status || row.dataset.status === status;
                const visible = matchesText && matchesStatus;
                row.style.display = visible ? '' : 'none';
                if (visible) visibleCount++;
            });

            if (reconNoMatch) {
                const hasRows = rows.length > 0;
                reconTable.hidden = visibleCount === 0 && hasRows;
                reconNoMatch.hidden = !(visibleCount === 0 && hasRows);
            }
        }

        if (reconSearch) {
            reconSearch.addEventListener('input', applyFilters);
            reconStatusFilter.addEventListener('change', applyFilters);
            reconResetBtn.addEventListener('click', () => {
                reconSearch.value = '';
                reconStatusFilter.value = '';
                applyFilters();
            });
        }

        function postReview(action, reconciliationId, button) {
            const row = button.closest('tr');
            const otherBtn = row.querySelector(action === 'approve_adjustment' ? '.recon-return-btn' : '.recon-approve-btn');
            button.disabled = true;
            if (otherBtn) otherBtn.disabled = true;
            button.textContent = action === 'approve_adjustment' ? 'Approving\u2026' : 'Returning\u2026';

            const body = new URLSearchParams({
                action: action,
                csrf_token: csrfToken,
                reconciliation_id: reconciliationId,
            });

            fetch('reconciliation-review-actions.php', {
                    method: 'POST',
                    body: body,
                })
                .then(res => res.json())
                .then(data => {
                    if (!data.success) {
                        button.disabled = false;
                        if (otherBtn) otherBtn.disabled = false;
                        button.textContent = action === 'approve_adjustment' ? 'Approve Adjustment' : 'Return for Revision';
                        showToast(data.error || 'Something went wrong. Please try again.');
                        return;
                    }
                    showToast(data.message);
                    setTimeout(() => window.location.reload(), 700);
                })
                .catch(() => {
                    button.disabled = false;
                    if (otherBtn) otherBtn.disabled = false;
                    button.textContent = action === 'approve_adjustment' ? 'Approve Adjustment' : 'Return for Revision';
                    showToast('Could not reach the server. Please check your connection and try again.');
                });
        }

        document.querySelectorAll('.recon-approve-btn').forEach(btn => {
            btn.addEventListener('click', () => postReview('approve_adjustment', btn.dataset.id, btn));
        });
        document.querySelectorAll('.recon-return-btn').forEach(btn => {
            btn.addEventListener('click', () => postReview('return_for_revision', btn.dataset.id, btn));
        });

        // Same "open a clean read-only tab and trigger the print dialog"
        // mechanism pharmacist/reports.php uses for Export PDF.
        document.getElementById('exportPdfBtn').addEventListener('click', () => {
            if (!reconTable) {
                alert('There are no records to export yet.');
                return;
            }
            const win = window.open('', '_blank', 'width=960,height=720');
            if (!win) {
                alert('Please allow pop-ups for this site to export a PDF.');
                return;
            }
            const generatedAt = new Date().toLocaleString('en-US', {
                dateStyle: 'medium',
                timeStyle: 'short'
            });
            win.document.write(`<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Reconciliation Review - GabayMed</title>
<style>
    body { font-family: "Segoe UI", Roboto, -apple-system, BlinkMacSystemFont, sans-serif; color:#1c2733; margin:0; padding:36px; }
    .pdf-header { display:flex; align-items:baseline; justify-content:space-between; border-bottom:2px solid #14b8a6; padding-bottom:12px; margin-bottom:6px; }
    .pdf-header h1 { margin:0; font-size:19px; color:#0f9c8d; }
    .pdf-brand { font-size:12px; font-weight:700; color:#1c2733; }
    .pdf-meta { font-size:12px; color:#6b7785; margin-bottom:22px; }
    table { width:100%; border-collapse:collapse; font-size:12.5px; }
    th, td { text-align:left; padding:9px 10px; border-bottom:1px solid #e7eaee; }
    th { background:#e6fbf8; color:#0f9c8d; font-weight:700; }
</style>
</head>
<body>
    <div class="pdf-header">
        <h1>Reconciliation Review</h1>
        <span class="pdf-brand">GabayMed &middot; Admin Portal</span>
    </div>
    <div class="pdf-meta">Generated ${generatedAt}</div>
    <table>
        <thead><tr><th>Medicine</th><th>Period</th><th>System</th><th>Actual</th><th>Result</th><th>Submitted By</th><th>Date</th><th>Review Status</th></tr></thead>
        <tbody>${Array.from(reconTableBody.querySelectorAll('tr')).map(row => {
            const cells = Array.from(row.querySelectorAll('td')).slice(0, 8).map(td => `<td>${td.textContent.trim()}</td>`).join('');
            return `<tr>${cells}</tr>`;
        }).join('')}</tbody>
    </table>
</body>
</html>`);
            win.document.close();
            win.focus();
            setTimeout(() => {
                try {
                    win.print();
                } catch (e) {}
            }, 200);
        });

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