<?php
// patient/blocked.php
// Implements spec 1.7 — Blocked Account Notice.
//
// This screen ENTIRELY replaces the dashboard for status = blocked.
// It is intentionally a dead-end: the only action available is Log Out.
// Unblocking can only happen via Admin's Account Management module.

require_once '../includes/auth_guard.php';
require_role('patient');
require_once '../config/db.php';

// Re-verify the patient is actually blocked — if their status changed
// (e.g. an admin unblocked them) since they last loaded this page,
// send them back to the real dashboard instead of trapping them here.
$user_id = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT status FROM users WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$status = $stmt->get_result()->fetch_assoc()['status'];
$stmt->close();

if ($status !== 'blocked') {
    header("Location: dashboard.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Blocked - GabayMed</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        body {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            background: var(--bg-light);
        }
        .blocked-card {
            background: var(--white);
            border: 1px solid var(--border-color);
            border-radius: 14px;
            padding: 40px;
            max-width: 440px;
            text-align: center;
        }
        .blocked-icon {
            width: 48px;
            height: 48px;
            margin: 0 auto 18px;
            color: var(--amber);
        }
        .blocked-title {
            font-size: 20px;
            font-weight: 800;
            color: var(--text-dark);
            margin-bottom: 12px;
        }
        .blocked-text {
            font-size: 14px;
            color: var(--text-muted);
            line-height: 1.6;
            margin-bottom: 10px;
        }
        .blocked-contact {
            font-size: 14px;
            color: var(--text-dark);
            font-weight: 600;
            margin-bottom: 28px;
        }
    </style>
</head>
<body>
    <div class="blocked-card">
        <svg class="blocked-icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
            <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/>
            <path d="M12 8v4m0 4h.01" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
        </svg>
        <div class="blocked-title">Account Temporarily Blocked</div>
        <p class="blocked-text">Your account has been temporarily blocked after 3 missed appointments.</p>
        <p class="blocked-contact">Please visit the front desk or call the clinic to reactivate your account.</p>
        <a href="../logout.php" class="btn btn-primary">Log Out</a>
    </div>
</body>
</html>
