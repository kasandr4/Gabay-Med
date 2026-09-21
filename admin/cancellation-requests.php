<?php
// admin/cancellation-requests.php
// Module — Appointment Cancellation Requests (2026-09-07).
//
// Per instructor requirement: patients no longer cancel their own
// appointments outright — patient/book-appointment.php and
// patient/appointment-detail.php both file a request (with a required
// reason) via includes/cancellation_policy.php's
// request_appointment_cancellation() instead. This page is where admin
// reviews those requests. Approve defers to the EXISTING
// cancel_patient_appointment() (unchanged — same late-cancel tracking,
// doctor notify, waitlist notify it's always done). Reject leaves the
// appointment completely untouched and notifies the patient with an
// optional note.
//
// Same read-then-act shape as admin/reschedule-history.php (a read-only
// history table further down this page) plus a small action panel for
// the still-pending rows — reuses the shared .card/.table-wrap/
// .records-table styling, no new CSS file.

require_once '../includes/auth_guard.php';
require_role('admin');
require_once '../config/db.php';
require_once '../includes/csrf.php';

$today = date("F j, Y");
$current_page = 'cancellation-requests';

$pending = $conn->query(
    "SELECT r.request_id, r.reason, r.was_late, r.requested_at,
            a.appointment_id, a.slot_start, d.department_name,
            pat.first_name AS pat_first, pat.last_name AS pat_last,
            doc.first_name AS doc_first, doc.last_name AS doc_last
     FROM appointment_cancellation_requests r
     JOIN appointments a ON a.appointment_id = r.appointment_id
     JOIN departments d ON d.department_id = a.department_id
     JOIN users pat ON pat.user_id = r.patient_id
     JOIN users doc ON doc.user_id = a.doctor_id
     WHERE r.status = 'pending'
     ORDER BY r.requested_at ASC"
)->fetch_all(MYSQLI_ASSOC);

$decided = $conn->query(
    "SELECT r.request_id, r.reason, r.status, r.decision_note, r.decided_at,
            a.slot_start, d.department_name,
            pat.first_name AS pat_first, pat.last_name AS pat_last,
            admin.first_name AS admin_first, admin.last_name AS admin_last
     FROM appointment_cancellation_requests r
     JOIN appointments a ON a.appointment_id = r.appointment_id
     JOIN departments d ON d.department_id = a.department_id
     JOIN users pat ON pat.user_id = r.patient_id
     LEFT JOIN users admin ON admin.user_id = r.decided_by
     WHERE r.status != 'pending'
     ORDER BY r.decided_at DESC
     LIMIT 25"
)->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cancellation Requests - GabayMed</title>
    <link rel="stylesheet" href="../assets/css/doctor-dashboard.css">
    <link rel="stylesheet" href="../assets/css/admin-dashboard.css">
    <style>
        .cr-reason {
            max-width: 280px;
            white-space: normal;
        }

        .cr-actions {
            display: flex;
            gap: 8px;
        }

        .cr-late-flag {
            display: inline-block;
            margin-left: 6px;
            font-size: 12px;
            color: #8A5A00;
            background: #FEF3E2;
            border-radius: 6px;
            padding: 2px 6px;
        }
    </style>
</head>

<body>

    <div class="app-shell">

        <?php include 'includes/sidebar.php'; ?>

        <main class="main-content">

            <header class="page-header">
                <div>
                    <h1>Cancellation Requests</h1>
                    <p class="page-subtitle">Review and decide on patient-submitted appointment cancellations.</p>
                </div>
                <div class="header-actions">
                    <span class="header-date"><?php echo htmlspecialchars($today); ?></span>
                </div>
            </header>

            <p class="scan-inline-error" id="cr-page-error" style="display:block;" hidden></p>

            <section class="card">
                <div class="card-header">
                    <div>
                        <h2 id="cr-pending-count"><?php echo count($pending); ?> Pending Request<?php echo count($pending) === 1 ? '' : 's'; ?></h2>
                        <span class="card-subtitle">Appointment stays confirmed until you decide</span>
                    </div>
                </div>

                <?php if (empty($pending)): ?>
                    <p class="schedule-day-empty" style="padding: 0 20px 20px;">No cancellation requests are waiting for review.</p>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="records-table" id="cr-pending-table">
                            <thead>
                                <tr>
                                    <th>Patient</th>
                                    <th>Doctor</th>
                                    <th>Department</th>
                                    <th>Appointment Time</th>
                                    <th>Reason</th>
                                    <th>Requested</th>
                                    <th>Decision</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pending as $r): ?>
                                    <tr data-request-id="<?php echo (int) $r['request_id']; ?>"
                                        data-patient-name="<?php echo htmlspecialchars($r['pat_first'] . ' ' . $r['pat_last']); ?>"
                                        data-department="<?php echo htmlspecialchars($r['department_name']); ?>"
                                        data-slot-label="<?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($r['slot_start']))); ?>"
                                        data-reason="<?php echo htmlspecialchars($r['reason']); ?>">
                                        <td><?php echo htmlspecialchars($r['pat_first'] . ' ' . $r['pat_last']); ?></td>
                                        <td>Dr. <?php echo htmlspecialchars($r['doc_first'] . ' ' . $r['doc_last']); ?></td>
                                        <td><?php echo htmlspecialchars($r['department_name']); ?></td>
                                        <td>
                                            <?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($r['slot_start']))); ?>
                                            <?php if ($r['was_late']): ?>
                                                <span class="cr-late-flag">Late</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="cr-reason"><?php echo nl2br(htmlspecialchars($r['reason'])); ?></td>
                                        <td><?php echo htmlspecialchars(date('M j, g:i A', strtotime($r['requested_at']))); ?></td>
                                        <td>
                                            <div class="cr-actions">
                                                <button type="button" class="btn btn-sm btn-primary cr-approve-btn">Approve</button>
                                                <button type="button" class="btn btn-sm btn-cancel cr-reject-btn">Reject</button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>

            <!-- Always renders the table shell (with a stable #cr-decided-tbody
                 and an #cr-decided-empty placeholder row for the empty
                 case) rather than swapping between a <p> and a <table>
                 depending on $decided - the Approve/Reject JS below needs
                 a tbody to insert the freshly-decided row into without a
                 page reload, which isn't possible if the table doesn't
                 exist in the DOM yet when the page loaded empty. -->
            <section class="card" style="margin-top: 20px;">
                <div class="card-header">
                    <div>
                        <h2>Recently Decided</h2>
                        <span class="card-subtitle">Last 25, most recent first</span>
                    </div>
                </div>

                <div class="table-wrap">
                    <table class="records-table">
                        <thead>
                            <tr>
                                <th>Patient</th>
                                <th>Department</th>
                                <th>Appointment Time</th>
                                <th>Reason</th>
                                <th>Outcome</th>
                                <th>Decided By</th>
                                <th>Decided At</th>
                            </tr>
                        </thead>
                        <tbody id="cr-decided-tbody">
                            <?php if (empty($decided)): ?>
                                <tr id="cr-decided-empty">
                                    <td colspan="7" class="schedule-day-empty" style="padding: 12px 0;">No cancellation requests have been decided yet.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($decided as $r): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($r['pat_first'] . ' ' . $r['pat_last']); ?></td>
                                        <td><?php echo htmlspecialchars($r['department_name']); ?></td>
                                        <td><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($r['slot_start']))); ?></td>
                                        <td class="cr-reason"><?php echo nl2br(htmlspecialchars($r['reason'])); ?></td>
                                        <td>
                                            <span class="status-chip status-chip-<?php echo $r['status'] === 'approved' ? 'cancelled' : 'confirmed'; ?>">
                                                <?php echo ucfirst($r['status']); ?>
                                            </span>
                                            <?php if (!empty($r['decision_note'])): ?>
                                                <div style="font-size:12px; color:var(--text-muted); margin-top:4px;"><?php echo htmlspecialchars($r['decision_note']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo $r['admin_first'] ? htmlspecialchars($r['admin_first'] . ' ' . $r['admin_last']) : '—'; ?></td>
                                        <td><?php echo $r['decided_at'] ? htmlspecialchars(date('M j, g:i A', strtotime($r['decided_at']))) : '—'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <footer class="page-footer">
                <p>&copy; <?php echo date("Y"); ?> GabayMed Hospital Management System</p>
            </footer>

        </main>
    </div>

    <!-- Reject confirmation modal — a rejection can optionally include a
         short note back to the patient, so it needs the same
         type-then-confirm shape as staff/dispense-stock.php's void modal
         rather than a plain confirm(). -->
    <div class="modal-backdrop" id="cr-reject-modal-backdrop"></div>
    <div class="modal-card" id="cr-reject-modal" role="dialog" aria-modal="true" aria-labelledby="cr-reject-modal-title">
        <div class="modal-header">
            <h2 class="modal-title" id="cr-reject-modal-title">Reject this cancellation request?</h2>
            <button class="modal-close-btn" type="button" id="cr-reject-modal-close-btn" aria-label="Close">
                <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M18 6L6 18M6 6l12 12" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                </svg>
            </button>
        </div>
        <div class="modal-body">
            <p>The appointment stays confirmed exactly as it is. The patient will be notified, along with any note you add below.</p>
            <div class="form-group" style="text-align:left; margin-top:12px;">
                <label for="cr-reject-note" class="form-label">Note to patient (optional)</label>
                <textarea id="cr-reject-note" class="form-textarea" rows="3" maxlength="500"
                    placeholder="e.g. Please contact the front desk to reschedule instead"></textarea>
            </div>
            <div style="display:flex; gap:12px; margin-top:20px;">
                <button type="button" class="btn btn-secondary" id="cr-reject-cancel-btn" style="flex:1;">Never Mind</button>
                <button type="button" class="btn btn-cancel" id="cr-reject-confirm-btn" style="flex:1;">Reject Request</button>
            </div>
        </div>
    </div>

    <script>
        const csrfToken = "<?php echo csrf_token(); ?>";
        const adminFullName = "<?php echo htmlspecialchars(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''), ENT_QUOTES); ?>";
        const pageError = document.getElementById('cr-page-error');
        const decidedTbody = document.getElementById('cr-decided-tbody');
        const pendingCountEl = document.getElementById('cr-pending-count');
        const pendingTbody = document.querySelector('#cr-pending-table tbody');

        function decrementPendingCount() {
            if (!pendingCountEl) {
                return;
            }
            const remaining = pendingTbody ? pendingTbody.querySelectorAll('tr').length - 1 : 0;
            pendingCountEl.textContent = remaining + ' Pending Request' + (remaining === 1 ? '' : 's');
        }

        function showPageError(message) {
            pageError.textContent = message;
            pageError.hidden = false;
        }

        function formatNow() {
            return new Date().toLocaleString('en-US', {
                month: 'short',
                day: 'numeric',
                hour: 'numeric',
                minute: '2-digit',
                hour12: true,
            });
        }

        // Inserts a freshly-decided row at the top of the Recently Decided
        // table (it's ordered most-recent-first) so approving/rejecting
        // shows up immediately instead of only after a page reload.
        function prependDecidedRow(row, outcome, note) {
            const emptyRow = document.getElementById('cr-decided-empty');
            if (emptyRow) {
                emptyRow.remove();
            }

            const tr = document.createElement('tr');
            const chipClass = outcome === 'approved' ? 'status-chip-cancelled' : 'status-chip-confirmed';
            const outcomeLabel = outcome === 'approved' ? 'Approved' : 'Rejected';
            const noteHtml = note ?
                '<div style="font-size:12px; color:var(--text-muted); margin-top:4px;">' + escapeHtml(note) + '</div>' :
                '';

            tr.innerHTML =
                '<td>' + escapeHtml(row.dataset.patientName) + '</td>' +
                '<td>' + escapeHtml(row.dataset.department) + '</td>' +
                '<td>' + escapeHtml(row.dataset.slotLabel) + '</td>' +
                '<td class="cr-reason">' + escapeHtml(row.dataset.reason) + '</td>' +
                '<td><span class="status-chip ' + chipClass + '">' + outcomeLabel + '</span>' + noteHtml + '</td>' +
                '<td>' + escapeHtml(adminFullName) + '</td>' +
                '<td>' + formatNow() + '</td>';

            decidedTbody.insertBefore(tr, decidedTbody.firstChild);
        }

        function escapeHtml(str) {
            const div = document.createElement('div');
            div.textContent = str || '';
            return div.innerHTML;
        }

        function postAction(body) {
            return fetch('cancellation-requests-actions.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: new URLSearchParams(body),
                })
                .then(res => res.json())
                .catch(() => ({
                    success: false,
                    error: 'Could not reach the server. Please check your connection and try again.'
                }));
        }

        // ---------------- Approve ----------------
        document.querySelectorAll('.cr-approve-btn').forEach(function(btn) {
            btn.addEventListener('click', async function() {
                const row = btn.closest('tr');
                const requestId = row.dataset.requestId;
                btn.disabled = true;
                btn.textContent = 'Approving…';

                const data = await postAction({
                    action: 'approve_cancellation_request',
                    csrf_token: csrfToken,
                    request_id: requestId,
                });

                if (!data.success) {
                    btn.disabled = false;
                    btn.textContent = 'Approve';
                    showPageError(data.error || 'Something went wrong. Please try again.');
                    return;
                }

                prependDecidedRow(row, 'approved', '');
                decrementPendingCount();
                row.remove();
            });
        });

        // ---------------- Reject ----------------
        const rejectBackdrop = document.getElementById('cr-reject-modal-backdrop');
        const rejectModal = document.getElementById('cr-reject-modal');
        const rejectNote = document.getElementById('cr-reject-note');
        const rejectConfirmBtn = document.getElementById('cr-reject-confirm-btn');
        let rejectTargetRow = null;

        function openRejectModal(row) {
            rejectTargetRow = row;
            rejectNote.value = '';
            rejectConfirmBtn.disabled = false;
            rejectConfirmBtn.textContent = 'Reject Request';
            rejectBackdrop.classList.add('modal-visible');
            rejectModal.classList.add('modal-visible');
            document.body.classList.add('modal-open');
        }

        function closeRejectModal() {
            rejectBackdrop.classList.remove('modal-visible');
            rejectModal.classList.remove('modal-visible');
            document.body.classList.remove('modal-open');
            rejectTargetRow = null;
        }

        document.querySelectorAll('.cr-reject-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                openRejectModal(btn.closest('tr'));
            });
        });

        document.getElementById('cr-reject-cancel-btn').addEventListener('click', closeRejectModal);
        document.getElementById('cr-reject-modal-close-btn').addEventListener('click', closeRejectModal);
        rejectBackdrop.addEventListener('click', closeRejectModal);

        rejectConfirmBtn.addEventListener('click', async function() {
            if (!rejectTargetRow) {
                return;
            }
            rejectConfirmBtn.disabled = true;
            rejectConfirmBtn.textContent = 'Rejecting…';

            const data = await postAction({
                action: 'reject_cancellation_request',
                csrf_token: csrfToken,
                request_id: rejectTargetRow.dataset.requestId,
                note: rejectNote.value.trim(),
            });

            if (!data.success) {
                rejectConfirmBtn.disabled = false;
                rejectConfirmBtn.textContent = 'Reject Request';
                showPageError(data.error || 'Something went wrong. Please try again.');
                closeRejectModal();
                return;
            }

            prependDecidedRow(rejectTargetRow, 'rejected', rejectNote.value.trim());
            decrementPendingCount();
            rejectTargetRow.remove();
            closeRejectModal();
        });
    </script>
</body>

</html>