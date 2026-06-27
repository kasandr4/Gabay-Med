<?php
require_once '../includes/auth_guard.php';
require_role('patient');
require_once '../config/db.php';

$active_page = 'profile';

// Pull the patient's own info to display read-only for now
$stmt = $conn->prepare("SELECT first_name, last_name, phone_number, email, birthdate, sex, address, philhealth_id FROM users WHERE user_id = ?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$patient = $stmt->get_result()->fetch_assoc();
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - GabayMed</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css">
</head>

<body>
    <div class="app-layout">
        <?php include 'includes/sidebar.php'; ?>
        <main class="main-content">
            <div class="page-header">
                <h1 class="page-title">My Profile</h1>
                <p class="page-subtitle">Editing isn't available yet — this is a read-only preview.</p>
            </div>
            <div class="card">
                <div class="card-title">Personal Information</div>
                <p style="margin-bottom:10px;"><strong>Name:</strong> <?= htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']) ?></p>
                <p style="margin-bottom:10px;"><strong>Mobile Number:</strong> <?= htmlspecialchars($patient['phone_number']) ?></p>
                <p style="margin-bottom:10px;"><strong>Email:</strong> <?= htmlspecialchars($patient['email'] ?? 'Not provided') ?></p>
                <p style="margin-bottom:10px;"><strong>Date of Birth:</strong> <?= htmlspecialchars($patient['birthdate'] ?? 'Not provided') ?></p>
                <p style="margin-bottom:10px;"><strong>Sex:</strong> <?= htmlspecialchars($patient['sex'] ?? 'Not provided') ?></p>
                <p style="margin-bottom:10px;"><strong>PhilHealth ID:</strong> <?= htmlspecialchars($patient['philhealth_id'] ?? 'Not provided') ?></p>
                <p><strong>Address:</strong> <?= htmlspecialchars($patient['address'] ?? 'Not provided') ?></p>
            </div>
        </main>
    </div>
</body>

</html>