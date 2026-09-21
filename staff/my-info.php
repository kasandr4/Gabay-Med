<?php
// staff/my-info.php
// Staff's own profile information. Available to both staff subtypes.

require_once '../includes/auth_guard.php';
require_role('staff');
require_once '../config/db.php';

$staffId = (int) $_SESSION['user_id'];
$stmt = $conn->prepare(
    "SELECT u.first_name, u.last_name, u.email, u.phone_number,
            u.role, u.staff_type, u.department_id, d.department_name,
            u.status, u.account_status, u.is_active, u.created_at
     FROM users u
     LEFT JOIN departments d ON d.department_id = u.department_id
     WHERE u.user_id = ? AND u.role = 'staff'
     LIMIT 1"
);
$stmt->bind_param('i', $staffId);
$stmt->execute();
$staff = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$staff) {
    http_response_code(404);
    exit('Staff profile not found.');
}

$staffTypeLabels = [
    'front_desk' => 'Front Desk',
    'inventory' => 'Inventory Counting',
];
$roleLabel = 'Hospital Staff';
if (!empty($staff['staff_type'])) {
    $roleLabel .= ' · ' . ($staffTypeLabels[$staff['staff_type']] ?? ucfirst($staff['staff_type']));
}

if (!(int) $staff['is_active']) {
    $accountStatus = 'Deactivated';
} elseif ($staff['account_status'] === 'guest') {
    $accountStatus = 'Pending';
} elseif (in_array($staff['status'], ['blocked', 'confined', 'deceased'], true)) {
    $accountStatus = ucfirst($staff['status']);
} else {
    $accountStatus = 'Active';
}

$fullName = trim($staff['first_name'] . ' ' . $staff['last_name']);
$initials = strtoupper(substr($staff['first_name'], 0, 1) . substr($staff['last_name'], 0, 1));
$current_page = 'my-info';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Info - GabayMed</title>
    <link rel="stylesheet" href="../assets/css/doctor-dashboard.css">
    <link rel="stylesheet" href="../assets/css/staff-my-info.css">
</head>

<body>
    <div class="app-shell">
        <?php include 'includes/sidebar.php'; ?>
        <main class="main-content">
            <header class="page-header">
                <div>
                    <h1>My Info</h1>
                    <p class="page-subtitle">View the profile information associated with your staff account.</p>
                </div>

            </header>

            <section class="mi-profile-hero">
                <div class="mi-avatar"><?php echo htmlspecialchars($initials); ?></div>
                <div>
                    <h2><?php echo htmlspecialchars($fullName); ?></h2>
                    <p><?php echo htmlspecialchars($roleLabel); ?></p>
                </div>
                <span class="status-pill mi-status-<?php echo strtolower($accountStatus); ?>"><?php echo htmlspecialchars($accountStatus); ?></span>
            </section>

            <section class="mi-grid">
                <article class="card mi-card">
                    <div class="card-header">
                        <h2>Contact Information</h2>
                    </div>
                    <dl class="mi-details">
                        <div>
                            <dt>Email</dt>
                            <dd><?php echo htmlspecialchars($staff['email'] ?: 'Not provided'); ?></dd>
                        </div>
                        <div>
                            <dt>Phone</dt>
                            <dd><?php echo htmlspecialchars($staff['phone_number'] ?: 'Not provided'); ?></dd>
                        </div>
                    </dl>
                </article>
                <article class="card mi-card">
                    <div class="card-header">
                        <h2>Work Information</h2>
                    </div>
                    <dl class="mi-details">
                        <div>
                            <dt>Staff Type</dt>
                            <dd><?php echo htmlspecialchars($staffTypeLabels[$staff['staff_type']] ?? 'Staff'); ?></dd>
                        </div>
                        <div>
                            <dt>Department</dt>
                            <dd><?php echo htmlspecialchars($staff['department_name'] ?: 'No Department'); ?></dd>
                        </div>
                        <div>
                            <dt>Date Hired</dt>
                            <dd><?php echo htmlspecialchars(date('M j, Y', strtotime($staff['created_at']))); ?></dd>
                        </div>
                    </dl>
                </article>
            </section>
        </main>
    </div>
    <script src="../assets/js/doctor-dashboard.js"></script>
</body>

</html>