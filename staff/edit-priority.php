<?php
// staff/edit-priority.php
// Front-desk screen: search for an existing patient and change their
// priority_type (Senior Citizen / PWD / IP / Regular).
//
// Why this page exists: priority_type can currently only be SET at
// register.php (self-registration) or, as of the walk-in.php update, at
// walk-in intake for a brand-new guest patient. Neither path lets staff
// go back and correct/update it for a patient who already has a record —
// e.g. someone registered before this feature existed and is stuck at
// 'regular', or a patient who acquires PWD/senior status after their
// first visit. This page is the smallest real fix for that gap: search,
// see current value, change it, save. It intentionally does not touch
// any other patient field.
//
// Same search pattern as staff/walk-in.php's Step 0a, kept separate from
// that page rather than merged into it, since walk-in.php's job is
// booking a same-day appointment - editing a patient's record isn't part
// of that flow and shouldn't be bundled into it.

require_once '../includes/auth_guard.php';
require_role('staff');
require_once '../config/db.php';
require_once '../includes/csrf.php';

$current_page = 'edit-priority';

$priorityLabels = [
    'senior'  => 'Senior Citizen',
    'pwd'     => 'PWD',
    'ip'      => 'IP',
    'regular' => 'Regular',
];
$allowed_priority_types = ['regular', 'senior', 'pwd', 'ip'];

$flash = $_SESSION['edit_priority_flash'] ?? null;
unset($_SESSION['edit_priority_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf('edit-priority.php', 'edit_priority_flash');
}

// ============================================================
// Search for a patient (name or phone number)
// ============================================================
$search_query = trim($_GET['q'] ?? $_POST['search_query'] ?? '');
$search_results = [];
$searched = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'search_patient') {
    $searched = true;
    $search_query = trim($_POST['search_query'] ?? '');
} elseif ($search_query !== '') {
    $searched = true;
}

if ($searched && $search_query !== '') {
    $like = '%' . $search_query . '%';
    $stmt = $conn->prepare("
        SELECT user_id, first_name, last_name, phone_number, account_status, priority_type
        FROM users
        WHERE role = 'patient'
          AND (first_name LIKE ? OR last_name LIKE ? OR phone_number LIKE ?)
        ORDER BY last_name, first_name
        LIMIT 20
    ");
    $stmt->bind_param("sss", $like, $like, $like);
    $stmt->execute();
    $search_results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// ============================================================
// Load the currently selected patient (for the edit form)
// ============================================================
$selected_patient = null;
$selected_id = isset($_GET['patient_id']) ? (int) $_GET['patient_id']
    : (isset($_POST['patient_id']) ? (int) $_POST['patient_id'] : 0);

if ($selected_id > 0) {
    $stmt = $conn->prepare("
        SELECT user_id, first_name, last_name, phone_number, account_status, priority_type
        FROM users
        WHERE user_id = ? AND role = 'patient'
        LIMIT 1
    ");
    $stmt->bind_param("i", $selected_id);
    $stmt->execute();
    $selected_patient = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// ============================================================
// Save the new priority_type
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_priority') {
    $patient_id = (int) ($_POST['patient_id'] ?? 0);
    $new_priority = trim($_POST['priority_type'] ?? '');

    if ($patient_id <= 0 || !$selected_patient) {
        $_SESSION['edit_priority_flash'] = ["type" => "error", "message" => "Patient not found. Please search again."];
        header("Location: edit-priority.php");
        exit;
    }

    if (!in_array($new_priority, $allowed_priority_types, true)) {
        $_SESSION['edit_priority_flash'] = ["type" => "error", "message" => "Please select a valid priority type."];
        header("Location: edit-priority.php?patient_id=" . $patient_id);
        exit;
    }

    // Not verified against an ID here either — same self-declared /
    // staff-declared trust model as register.php and walk-in.php. If a
    // per-change audit trail (who changed it, when) is ever needed, that
    // would mean adding priority_changed_by / priority_changed_at columns
    // and writing them here — not currently tracked.
    $stmt = $conn->prepare("UPDATE users SET priority_type = ? WHERE user_id = ? AND role = 'patient'");
    $stmt->bind_param("si", $new_priority, $patient_id);
    $stmt->execute();
    $stmt->close();

    $patientName = trim($selected_patient['first_name'] . ' ' . $selected_patient['last_name']);
    $_SESSION['edit_priority_flash'] = [
        "type" => "success",
        "message" => "Updated " . $patientName . "'s priority to " . ($priorityLabels[$new_priority] ?? 'Regular') . "."
    ];
    header("Location: edit-priority.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Patient Priority - GabayMed</title>
    <link rel="stylesheet" href="../assets/css/doctor-dashboard.css">
</head>

<body>

    <div class="app-shell">

        <?php include 'includes/sidebar.php'; ?>

        <main class="main-content" style="margin: 0 auto; max-width: 820px;">

            <header class="page-header">
                <div>
                    <h1>Edit Patient Priority</h1>
                    <p class="page-subtitle">Update Senior Citizen / PWD / IP priority for an existing patient record.</p>
                </div>
            </header>

            <?php if ($flash): ?>
                <section class="card" style="border-left: 4px solid <?php echo $flash['type'] === 'error' ? 'var(--red)' : 'var(--teal)'; ?>; margin-bottom: 20px;">
                    <p style="margin: 0; font-size: 14px; color: <?php echo $flash['type'] === 'error' ? 'var(--red)' : 'var(--text-primary)'; ?>;">
                        <?php echo htmlspecialchars($flash['message']); ?>
                    </p>
                </section>
            <?php endif; ?>

            <?php if ($selected_patient): ?>
                <!-- Edit form for the selected patient -->
                <section class="card">
                    <div class="card-header">
                        <h2>Edit Priority — <?php echo htmlspecialchars(trim($selected_patient['first_name'] . ' ' . $selected_patient['last_name'])); ?></h2>
                    </div>
                    <div class="patient-info-grid" style="margin-bottom: 16px;">
                        <div class="info-item">
                            <span class="info-label">Phone</span>
                            <span class="info-value"><?php echo htmlspecialchars($selected_patient['phone_number'] ?: '—'); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Account</span>
                            <span class="info-value"><?php echo $selected_patient['account_status'] === 'guest' ? '<span class="status-pill status-guest">Guest</span>' : '<span class="status-pill status-active">Active</span>'; ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Current Priority</span>
                            <span class="info-value"><span class="priority-badge priority-<?php echo htmlspecialchars($selected_patient['priority_type']); ?>"><?php echo htmlspecialchars($priorityLabels[$selected_patient['priority_type']] ?? 'Regular'); ?></span></span>
                        </div>
                    </div>

                    <form method="POST" action="edit-priority.php" class="consultation-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update_priority">
                        <input type="hidden" name="patient_id" value="<?php echo (int) $selected_patient['user_id']; ?>">
                        <div class="form-group">
                            <label class="form-label">New Priority</label>
                            <select name="priority_type" class="form-input">
                                <?php foreach ($priorityLabels as $value => $label): ?>
                                    <option value="<?php echo htmlspecialchars($value); ?>" <?php echo $selected_patient['priority_type'] === $value ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <span class="form-hint">Confirm against a valid ID (Senior Citizen / PWD card, or applicable IP certification) before changing this.</span>
                        </div>
                        <div style="display:flex; gap:10px; margin-top: 12px;">
                            <button type="submit" class="btn btn-primary">Save Priority</button>
                            <a href="edit-priority.php<?php echo $search_query !== '' ? '?q=' . urlencode($search_query) : ''; ?>" class="btn btn-secondary">Cancel</a>
                        </div>
                    </form>
                </section>
            <?php else: ?>
                <!-- Search -->
                <section class="card">
                    <div class="card-header">
                        <h2>Find Patient</h2>
                    </div>
                    <form method="GET" action="edit-priority.php" class="consultation-form">
                        <div class="form-group">
                            <label for="q" class="form-label">Name or phone number</label>
                            <input type="text" id="q" name="q" class="form-input" value="<?php echo htmlspecialchars($search_query); ?>" autofocus>
                        </div>
                        <button type="submit" class="btn btn-primary">Search</button>
                    </form>
                </section>

                <?php if ($searched): ?>
                    <section class="card" style="margin-top: 20px;">
                        <div class="card-header">
                            <h2>Results</h2>
                        </div>
                        <?php if (count($search_results) > 0): ?>
                            <div class="table-wrap">
                                <table class="queue-table">
                                    <thead>
                                        <tr>
                                            <th>Name</th>
                                            <th>Phone</th>
                                            <th>Account</th>
                                            <th>Priority</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($search_results as $r): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars(trim($r['first_name'] . ' ' . $r['last_name'])); ?></td>
                                                <td><?php echo htmlspecialchars($r['phone_number'] ?: '—'); ?></td>
                                                <td><?php echo $r['account_status'] === 'guest' ? '<span class="status-pill status-guest">Guest</span>' : '<span class="status-pill status-active">Active</span>'; ?></td>
                                                <td><span class="priority-badge priority-<?php echo htmlspecialchars($r['priority_type']); ?>"><?php echo htmlspecialchars($priorityLabels[$r['priority_type']] ?? 'Regular'); ?></span></td>
                                                <td style="white-space: nowrap;">
                                                    <a href="edit-priority.php?patient_id=<?php echo (int) $r['user_id']; ?>&amp;q=<?php echo urlencode($search_query); ?>" class="btn btn-primary">Edit</a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <p>No matching patient found.</p>
                            </div>
                        <?php endif; ?>
                    </section>
                <?php endif; ?>
            <?php endif; ?>

            <footer class="page-footer">
                <p>&copy; <?php echo date("Y"); ?> GabayMed Hospital Management System</p>
            </footer>

        </main>
    </div>

    <script src="../assets/js/doctor-dashboard.js"></script>
</body>

</html>
