<?php
// doctor/priority-queue-preview.php
// Priority Queue Preview — UI ONLY, kept as a controllable demo/explainer.
//
// UPDATE (2026-07-20): the real version of this now exists —
// todays-queue.php sorts by u.priority_type for real, and
// register.php/sql/2026_07_20_add_priority_type.sql back it with an
// actual column. This page is left in place because it's still useful
// as a safe sandbox to show the sorting rule with any scenario you want
// (e.g. "what if 4 priority patients arrive back to back"), without
// needing real appointments/check-ins to demonstrate it. Nothing on
// this page reads from or affects the real queue.
//
// Answers the panel's ask for Senior Citizen / PWD / IP priority in the
// Appointment module. Deliberately built as a standalone preview page
// with mock data rather than modifying todays-queue.php directly —
// that page is real, working, and used to actually run the doctor's
// queue day-to-day, so it isn't the place to bolt on an unwired concept.
// This page is meant to make the *behavior* concrete first: what would
// change about queue order if priority type were captured at intake.
//
// STATUS (2026-07-20): real backend now exists for this — see
// sql/2026_07_20_add_priority_type.sql (priority_type on `users`,
// defaulting to 'regular'), register.php (self-declared at signup), and
// todays-queue.php (real ORDER BY sorts priority patients first). This
// preview page itself still uses its own in-memory mock list, on
// purpose — it's a sandbox, not a window into the real queue.
//
// Legal basis (PH context, for the record — not enforced by this code):
//   - RA 9994 (Expanded Senior Citizens Act of 2010) mandates express/
//     priority lanes for senior citizens in establishments and services.
//   - RA 10754 (2016) extends the same priority privileges to Persons
//     with Disability (PWD).
//   - Indigenous Peoples (IP) priority isn't a nationally mandated lane
//     the same way — if this hospital wants it, it would be a hospital
//     or LGU policy decision, worth confirming explicitly with your
//     adviser/panel rather than assuming it's legally required like the
//     other two.
//
// STILL MISSING, even with the above wired up:
//   1. Verification. priority_type is entirely self-declared at signup
//      right now — nothing checks it against an ID. Real priority lanes
//      are verified at the counter, not just typed into a form.
//   2. staff/walk-in.php and patient/book-appointment.php don't let
//      anyone set/change priority_type — only register.php does, at
//      signup. Existing patients registered before this migration are
//      all 'regular' by default until someone updates their row.
//   3. No admin/staff UI to edit an existing patient's priority_type
//      (would naturally live in the User Management module, but that
//      module itself is UI-only right now).

require_once '../includes/auth_guard.php';
require_role('doctor');

$priorityLabels = [
    'senior'  => 'Senior Citizen',
    'pwd'     => 'PWD',
    'ip'      => 'IP',
    'regular' => 'Regular',
];

// UI ONLY — mock queue for today.
$mockQueue = [
    ["name" => "Ramon Villanueva",   "time" => "09:00 AM", "priority" => "regular", "reason" => "Follow-up check-up"],
    ["name" => "Erlinda Bautista",   "time" => "09:15 AM", "priority" => "senior",  "reason" => "Hypertension monitoring"],
    ["name" => "Jerico Manalo",      "time" => "09:30 AM", "priority" => "regular", "reason" => "Fever and cough"],
    ["name" => "Marites Aquino",     "time" => "09:45 AM", "priority" => "pwd",     "reason" => "Post-surgery review"],
    ["name" => "Bienvenido Cruz",    "time" => "10:00 AM", "priority" => "senior",  "reason" => "Diabetes check-up"],
    ["name" => "Kalinaw Dumagat",    "time" => "10:15 AM", "priority" => "ip",      "reason" => "Prenatal check-up"],
    ["name" => "Anthony Reyes",      "time" => "10:30 AM", "priority" => "regular", "reason" => "Skin consultation"],
];

$current_page = 'priority-queue-preview';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Priority Queue Preview - GabayMed</title>
    <link rel="stylesheet" href="../assets/css/doctor-dashboard.css">
</head>

<body>

    <div class="app-shell">

        <?php include 'includes/sidebar.php'; ?>

        <main class="main-content">

            <header class="page-header">
                <div>
                    <h1>Priority Queue Preview</h1>
                    <p class="page-subtitle">A preview of Senior Citizen / PWD / IP priority queueing &mdash; separate from Today's Queue, which stays arrival-order only until this is wired up for real.</p>
                </div>
            </header>

            <section class="card pq-rules-card">
                <div class="card-header">
                    <h2>How Priority Order Would Work</h2>
                    <span class="card-subtitle">Not enforced anywhere yet &mdash; this is what the rule would be</span>
                </div>
                <div class="pq-rules-list">
                    <div class="pq-rule-row">
                        <span class="priority-badge priority-senior">Senior</span>
                        <span class="priority-badge priority-pwd">PWD</span>
                        <span class="priority-badge priority-ip">IP</span>
                        <span>go ahead of Regular patients, in the order they arrived among themselves.</span>
                    </div>
                    <div class="pq-rule-row">
                        <span class="priority-badge priority-regular">Regular</span>
                        <span>patients keep their normal arrival order, just pushed behind whichever priority patients are already waiting.</span>
                    </div>
                    <div class="pq-rule-row">
                        <span class="status-pill status-checked-in">Note</span>
                        <span>Senior Citizen and PWD priority lanes are mandated by RA 9994 and RA 10754. IP priority, if wanted, would be a hospital/LGU policy choice &mdash; worth confirming with your adviser rather than assuming it's legally required the same way.</span>
                    </div>
                </div>
            </section>

            <section class="card">
                <div class="card-header">
                    <h2>Today's Queue (Preview)</h2>
                    <span class="card-subtitle">Toggle to see the same list re-ordered</span>
                </div>

                <div class="toolbar-filters">
                    <div class="priority-toggle" role="group" aria-label="Queue order">
                        <button type="button" class="priority-toggle-btn active" data-order="arrival">Arrival Order</button>
                        <button type="button" class="priority-toggle-btn" data-order="priority">Priority Order</button>
                    </div>
                    <button type="button" class="btn btn-secondary" id="openAddToQueueBtn">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="12" y1="5" x2="12" y2="19"></line>
                            <line x1="5" y1="12" x2="19" y2="12"></line>
                        </svg>
                        Add to Queue
                    </button>
                </div>

                <div class="table-wrap">
                    <table class="queue-table" id="priorityQueueTable">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Patient</th>
                                <th>Arrival Time</th>
                                <th>Priority</th>
                                <th>Reason for Visit</th>
                            </tr>
                        </thead>
                        <tbody id="priorityQueueBody">
                            <?php foreach ($mockQueue as $p): ?>
                                <tr data-name="<?php echo htmlspecialchars($p['name']); ?>" data-time="<?php echo htmlspecialchars($p['time']); ?>" data-priority="<?php echo $p['priority']; ?>" data-reason="<?php echo htmlspecialchars($p['reason']); ?>">
                                    <td class="cell-rank"></td>
                                    <td><?php echo htmlspecialchars($p['name']); ?></td>
                                    <td><?php echo htmlspecialchars($p['time']); ?></td>
                                    <td><span class="priority-badge priority-<?php echo $p['priority']; ?>"><?php echo $priorityLabels[$p['priority']]; ?></span></td>
                                    <td><?php echo htmlspecialchars($p['reason']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

        </main>
    </div>

    <!-- Add to Queue — UI ONLY, prepends a row to the in-memory table
         above. Nothing here is saved, and this is not the real Walk-In
         intake form (staff/walk-in.php) or online booking wizard
         (patient/book-appointment.php) — deliberately kept separate so
         this preview can't be confused for, or accidentally break,
         either of those real, working flows. -->
    <div class="pq-modal-backdrop" id="addToQueueModal" aria-hidden="true">
        <div class="pq-modal-box" role="dialog" aria-modal="true" aria-labelledby="addToQueueModalTitle">
            <div class="pq-modal-header">
                <h3 id="addToQueueModalTitle">Add to Queue</h3>
                <button type="button" class="pq-modal-close" data-close-modal="addToQueueModal" aria-label="Close">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
            </div>
            <form id="addToQueueForm">
                <div class="pq-modal-body">
                    <div class="pq-field">
                        <label for="queuePatientName">Patient Name</label>
                        <input type="text" id="queuePatientName" required>
                    </div>
                    <div class="pq-field">
                        <label for="queueTime">Arrival Time</label>
                        <input type="time" id="queueTime" required>
                    </div>
                    <div class="pq-field">
                        <label for="queuePriority">Priority Type</label>
                        <select id="queuePriority">
                            <option value="regular">Regular</option>
                            <option value="senior">Senior Citizen</option>
                            <option value="pwd">PWD</option>
                            <option value="ip">IP</option>
                        </select>
                    </div>
                    <div class="pq-field">
                        <label for="queueReason">Reason for Visit</label>
                        <input type="text" id="queueReason" placeholder="e.g. Follow-up check-up">
                    </div>
                </div>
                <div class="pq-modal-actions">
                    <button type="button" class="btn btn-secondary" data-close-modal="addToQueueModal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add to Queue</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const PRIORITY_LABELS = { senior: 'Senior Citizen', pwd: 'PWD', ip: 'IP', regular: 'Regular' };
        // Lower rank = goes first. Regular is intentionally last.
        const PRIORITY_RANK = { senior: 0, pwd: 0, ip: 0, regular: 1 };

        const tableBody = document.getElementById('priorityQueueBody');
        let currentOrder = 'arrival';

        function timeToMinutes(label) {
            const d = new Date('1970-01-01 ' + label);
            if (isNaN(d.getTime())) return 0;
            return d.getHours() * 60 + d.getMinutes();
        }

        function renderOrder(order) {
            currentOrder = order;
            const rows = Array.from(tableBody.querySelectorAll('tr'));

            rows.sort((a, b) => {
                const timeA = timeToMinutes(a.dataset.time);
                const timeB = timeToMinutes(b.dataset.time);

                if (order === 'priority') {
                    const rankA = PRIORITY_RANK[a.dataset.priority] ?? 1;
                    const rankB = PRIORITY_RANK[b.dataset.priority] ?? 1;
                    if (rankA !== rankB) return rankA - rankB;
                }
                return timeA - timeB;
            });

            rows.forEach((row, i) => {
                row.querySelector('.cell-rank').textContent = i + 1;
                tableBody.appendChild(row);
            });
        }

        document.querySelectorAll('.priority-toggle-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('.priority-toggle-btn').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                renderOrder(btn.dataset.order);
            });
        });

        renderOrder('arrival');

        // ---------- Add to Queue modal ----------
        const addToQueueModal = document.getElementById('addToQueueModal');
        const addToQueueForm = document.getElementById('addToQueueForm');

        document.getElementById('openAddToQueueBtn').addEventListener('click', () => {
            addToQueueForm.reset();
            addToQueueModal.classList.add('active');
            document.body.classList.add('modal-open');
        });

        document.querySelectorAll('[data-close-modal="addToQueueModal"]').forEach(btn => {
            btn.addEventListener('click', closeAddToQueueModal);
        });
        addToQueueModal.addEventListener('click', (e) => {
            if (e.target === addToQueueModal) closeAddToQueueModal();
        });

        function closeAddToQueueModal() {
            addToQueueModal.classList.remove('active');
            document.body.classList.remove('modal-open');
        }

        addToQueueForm.addEventListener('submit', (e) => {
            e.preventDefault();

            const name = document.getElementById('queuePatientName').value.trim();
            const timeValue = document.getElementById('queueTime').value; // HH:MM (24h)
            const priority = document.getElementById('queuePriority').value;
            const reason = document.getElementById('queueReason').value.trim() || 'Not specified';
            if (!name || !timeValue) return;

            const timeLabel = new Date('1970-01-01T' + timeValue).toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });

            const row = document.createElement('tr');
            row.dataset.name = name;
            row.dataset.time = timeLabel;
            row.dataset.priority = priority;
            row.dataset.reason = reason;
            row.innerHTML = `
                <td class="cell-rank"></td>
                <td>${escapeHtml(name)}</td>
                <td>${escapeHtml(timeLabel)}</td>
                <td><span class="priority-badge priority-${priority}">${PRIORITY_LABELS[priority]}</span></td>
                <td>${escapeHtml(reason)}</td>
            `;
            tableBody.appendChild(row);

            renderOrder(currentOrder);
            closeAddToQueueModal();
        });

        function escapeHtml(str) {
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        }
    </script>
</body>

</html>
