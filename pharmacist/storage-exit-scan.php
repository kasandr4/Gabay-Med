<?php
// pharmacist/storage-exit-scan.php
// Module 3.4 — Storage Exit Scan ("Stock Release").
//
// PIVOT (2026-07-17): each scan logs and deducts one full BOX (not one
// unit tied to a patient/prescription). units_per_box comes from
// inventory_medicines — the scan itself is still exactly one tap/read per
// box, per your call that this should stay the same barcode-scan UX as
// before.
//
// REAL BACKEND (2026-07-20): staff roster and the scan submit are both
// real now — see exit-actions.php. That required adding a barcode column
// to inventory_medicines (inventory_medicines_barcode_migration.sql) —
// it never existed in the real schema, even though this page's own
// TODO(backend) comment already assumed a lookup against it. Run that
// migration before testing this page.
//
// A real USB CCD scanner behaves exactly like a keyboard typing into the
// focused input followed by an automatic Enter, which is exactly what the
// form's submit event below is built to catch.

require_once '../includes/auth_guard.php';
require_role('pharmacist');
require_once '../config/db.php';
require_once '../includes/csrf.php';

$staffOptions = [];
$result = $conn->query("SELECT user_id, first_name, last_name FROM users WHERE role IN ('pharmacist','admin') AND is_active = 1 ORDER BY first_name");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $staffOptions[] = [
            "id"   => (int) $row['user_id'],
            "name" => trim($row['first_name'] . ' ' . $row['last_name']),
        ];
    }
}

$current_page = 'exit-scan';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stock Release - GabayMed</title>
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
                    <h1>Stock Release</h1>
                    <p class="page-subtitle">Log every box of medicine physically leaving the storage room.</p>
                </div>
            </header>

            <!-- Deliberately minimal: staff selector + one large scan input.
                 No stats, no tables — this screen is used standing up,
                 scanning repeatedly, as fast as possible. -->
            <section class="card scan-card">

                <div class="scan-field">
                    <label for="staffSelect">Scanning as</label>
                    <select id="staffSelect">
                        <option value="">Select your name to begin scanning&hellip;</option>
                        <?php foreach ($staffOptions as $staff): ?>
                            <option value="<?php echo (int) $staff['id']; ?>"><?php echo htmlspecialchars($staff['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <form id="scanForm" autocomplete="off">
                    <?= csrf_field() ?>
                    <div class="scan-field">
                        <label for="barcodeInput">Scan or enter barcode</label>
                        <input
                            type="text"
                            id="barcodeInput"
                            class="scan-input"
                            placeholder="Select your name first"
                            disabled
                            autocomplete="off" />
                    </div>

                    <!-- UI ONLY: no destination/reason/remarks columns exist on
                         medicine_exit_log yet — these are captured into this
                         session's on-page log below, same as everything else
                         on this page, but not sent anywhere. Quantity mirrors
                         the "1 box per scan" rule this screen already
                         enforces, kept editable for a future multi-box scan. -->
                    <div class="exit-extra-fields">
                        <div class="scan-field">
                            <label for="exitDestination">Destination</label>
                            <select id="exitDestination">
                                <option value="">Select destination&hellip;</option>
                                <option value="Ward A">Ward A</option>
                                <option value="Ward B">Ward B</option>
                                <option value="Outpatient Pharmacy Counter">Outpatient Pharmacy Counter</option>
                                <option value="Emergency Room">Emergency Room</option>
                                <option value="Disposal / Write-off">Disposal / Write-off</option>
                            </select>
                        </div>
                        <div class="scan-field">
                            <label for="exitReason">Reason</label>
                            <select id="exitReason">
                                <option value="Dispensing">Dispensing</option>
                                <option value="Internal Transfer">Internal Transfer</option>
                                <option value="Damaged">Damaged</option>
                                <option value="Expired">Expired</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="scan-field">
                            <label for="exitQuantity">Quantity (Boxes)</label>
                            <input type="number" id="exitQuantity" min="1" step="1" value="1">
                        </div>
                        <div class="scan-field exit-field-full">
                            <label for="exitRemarks">Remarks (optional)</label>
                            <textarea id="exitRemarks" rows="2" placeholder="Anything worth noting about this exit"></textarea>
                        </div>
                    </div>
                </form>

                <p class="scan-inline-error" id="scanError" hidden></p>
            </section>

            <!-- Running log of this session's scans. Not part of the 3.4 spec
                 verbatim, but the screen needs *some* visible confirmation
                 trail beyond a toast that disappears in 2 seconds — this is
                 purely client-side/in-memory and resets on reload.
                 TODO(backend): replace with a live read of medicine_exit_log
                 for the current shift/day, most recent first. -->
            <section class="card">
                <div class="card-header">
                    <h2>Recent Scans (this session)</h2>
                </div>
                <div id="recentScansEmpty" class="empty-state">
                    <div class="empty-illustration">
                        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M3 7V5a2 2 0 0 1 2-2h2"></path>
                            <path d="M17 3h2a2 2 0 0 1 2 2v2"></path>
                            <path d="M21 17v2a2 2 0 0 1-2 2h-2"></path>
                            <path d="M7 21H5a2 2 0 0 1-2-2v-2"></path>
                            <line x1="7" y1="12" x2="17" y2="12"></line>
                        </svg>
                    </div>
                    <p>No scans logged yet this session</p>
                </div>
                <div class="table-wrap" id="recentScansTableWrap" hidden>
                    <table class="queue-table">
                        <thead>
                            <tr>
                                <th>Medicine</th>
                                <th>Barcode</th>
                                <th>Units Deducted</th>
                                <th>Destination</th>
                                <th>Reason</th>
                                <th>Scanned By</th>
                                <th>Time</th>
                            </tr>
                        </thead>
                        <tbody id="recentScansBody"></tbody>
                    </table>
                </div>
            </section>

            <footer class="page-footer">
                <p>&copy; <?php echo date("Y"); ?> GabayMed Hospital Management System</p>
            </footer>

        </main>
    </div>

    <div class="scan-toast-host" id="scanToastHost"></div>

    <script>
        // REAL BACKEND (2026-07-20): posts to exit-actions.php
        // (action=scan_exit), which looks the barcode up against
        // inventory_medicines, deducts current_stock in units, and logs
        // the exit to medicine_exit_log. See that file for why staff_id
        // is re-validated server-side.
        const staffSelect = document.getElementById('staffSelect');
        const barcodeInput = document.getElementById('barcodeInput');
        const scanForm = document.getElementById('scanForm');
        const scanError = document.getElementById('scanError');
        const toastHost = document.getElementById('scanToastHost');
        const recentScansEmpty = document.getElementById('recentScansEmpty');
        const recentScansTableWrap = document.getElementById('recentScansTableWrap');
        const recentScansBody = document.getElementById('recentScansBody');

        // Barcode input stays disabled until a staff member is selected —
        // prevents an anonymous scan being logged with no staff attached.
        staffSelect.addEventListener('change', function() {
            if (staffSelect.value) {
                barcodeInput.disabled = false;
                barcodeInput.placeholder = 'Ready to scan\u2026';
                barcodeInput.focus();
            } else {
                barcodeInput.disabled = true;
                barcodeInput.placeholder = 'Select your name first';
            }
            hideError();
        });

        const exitDestination = document.getElementById('exitDestination');
        const exitReason = document.getElementById('exitReason');
        const exitQuantity = document.getElementById('exitQuantity');

        scanForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const code = barcodeInput.value.trim();
            if (!code) return;

            const staffName = staffSelect.options[staffSelect.selectedIndex].text;
            const destination = exitDestination.value || '\u2014';
            const reason = exitReason.value || '\u2014';
            const boxes = parseInt(exitQuantity.value, 10) || 1;
            const csrfToken = scanForm.querySelector('[name="csrf_token"]').value;

            barcodeInput.disabled = true;

            const body = new URLSearchParams({
                action: 'scan_exit',
                csrf_token: csrfToken,
                barcode: code,
                staff_id: staffSelect.value,
                boxes: boxes,
            });

            fetch('exit-actions.php', {
                    method: 'POST',
                    body: body,
                })
                .then(res => res.json())
                .then(data => {
                    barcodeInput.disabled = false;
                    barcodeInput.value = '';
                    barcodeInput.focus();

                    if (!data.success) {
                        showError(data.error || 'Something went wrong. Please try again.');
                        return;
                    }

                    logScan(data.medicine, code, data.units_deducted, data.boxes, staffName, destination, reason);
                    const boxWord = data.boxes === 1 ? 'box' : 'boxes';
                    showToast(`\u2713 Logged: ${data.boxes} ${boxWord} of ${data.medicine} (${data.units_deducted} units) \u2014 ${staffName}`);
                    hideError();
                })
                .catch(() => {
                    barcodeInput.disabled = false;
                    barcodeInput.value = '';
                    barcodeInput.focus();
                    showError('Could not reach the server. Please check your connection and try again.');
                });
        });

        function showError(message) {
            scanError.textContent = message;
            scanError.hidden = false;
        }

        function hideError() {
            scanError.hidden = true;
        }

        function logScan(medicine, code, unitsDeducted, boxes, staffName, destination, reason) {
            recentScansEmpty.hidden = true;
            recentScansTableWrap.hidden = false;

            const row = document.createElement('tr');
            const time = new Date().toLocaleTimeString([], {
                hour: '2-digit',
                minute: '2-digit'
            });
            row.innerHTML = `
                <td>${medicine}</td>
                <td>${code}</td>
                <td>${unitsDeducted} (${boxes} box${boxes === 1 ? '' : 'es'})</td>
                <td>${destination}</td>
                <td>${reason}</td>
                <td>${staffName}</td>
                <td>${time}</td>
            `;
            recentScansBody.insertBefore(row, recentScansBody.firstChild);
        }

        function showToast(message) {
            const toast = document.createElement('div');
            toast.className = 'scan-toast';
            toast.textContent = message;
            toastHost.appendChild(toast);
            requestAnimationFrame(() => toast.classList.add('scan-toast-show'));
            setTimeout(() => {
                toast.classList.remove('scan-toast-show');
                setTimeout(() => toast.remove(), 250);
            }, 2200);
        }
    </script>
</body>

</html>