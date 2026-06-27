<?php
require_once '../includes/auth_guard.php';
require_role('patient');

$active_page = 'book-appointment';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Book Appointment - GabayMed</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css">
</head>
<body>
<div class="app-layout">
    <?php include 'includes/sidebar.php'; ?>
    <main class="main-content">
        <div class="page-header">
            <h1 class="page-title">Book an Appointment</h1>
            <p class="page-subtitle">This module is coming soon.</p>
        </div>
        <div class="card">
            <div class="empty-state">
                <p class="empty-state-text">The booking flow is being built next — describe your symptoms, get a department recommendation, then pick a doctor and time slot.</p>
                <a href="dashboard.php" class="btn btn-secondary">Back to Dashboard</a>
            </div>
        </div>
    </main>
</div>
</body>
</html>
