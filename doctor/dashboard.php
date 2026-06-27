<?php
require_once '../includes/auth_guard.php';
require_role('doctor');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Doctor Dashboard - GabayMed</title>
    <style>
        body {
            font-family: 'Segoe UI', system-ui, sans-serif;
            background: #F4FAFA;
            margin: 0;
            padding: 40px;
            color: #1F2D2D;
        }
        .card {
            background: white;
            border: 1px solid #DCE6E6;
            border-radius: 12px;
            padding: 32px;
            max-width: 500px;
            margin: 60px auto;
            text-align: center;
        }
        h1 { color: #0B8FAC; margin-bottom: 8px; }
        p { color: #6B7B7B; margin-bottom: 24px; }
        a {
            display: inline-block;
            background: #0B8FAC;
            color: white;
            padding: 10px 24px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <div class="card">
        <h1>Doctor Dashboard</h1>
        <p>Welcome, Dr. <?= htmlspecialchars($_SESSION['last_name']) ?>! 🎉<br>
        Login is working. This page is a placeholder — confinement records, follow-ups, etc. will be built here next.</p>
        <a href="../logout.php">Log Out</a>
    </div>
</body>
</html>
