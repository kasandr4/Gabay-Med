<?php
// admin/print-user-profile.php
// PDF-style User Profile print preview — UI ONLY. Same pattern as
// print-purchase-order.php / doctor/print-consultation.php: a bare,
// self-contained HTML page meant to be opened in a new tab and printed
// (or saved as PDF via the browser print dialog), not styled like the
// app shell. Data comes from the same placeholder set the User
// Management table uses, via includes/user-management-data.php.
//
// TODO(backend): once the users API exists, replace um_find_user() below
// with a real SELECT ... FROM users WHERE user_id = ? lookup, the same
// way print-purchase-order.php pulls its record with a prepared statement.

require_once '../includes/auth_guard.php';
require_role('admin');
require_once 'includes/user-management-data.php';

$userId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;
$user = $userId > 0 ? um_find_user($placeholderUsers, $userId) : null;

if (!$user) {
    http_response_code(404);
    exit('User not found.');
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($user['full_name']); ?> - GabayMed User Profile</title>
    <style>
        :root {
            --teal: #14b8a6;
            --teal-dark: #0f9c8d;
            --border: #e0e4e8;
            --text: #1c2733;
            --muted: #6b7785;
        }

        * {
            box-sizing: border-box;
        }

        body {
            font-family: "Segoe UI", Roboto, -apple-system, BlinkMacSystemFont, sans-serif;
            color: var(--text);
            max-width: 760px;
            margin: 40px auto;
            padding: 0 24px;
        }

        .profile-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 3px solid var(--teal);
            padding-bottom: 16px;
            margin-bottom: 24px;
        }

        .profile-brand {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .profile-brand img {
            width: 42px;
            height: 42px;
        }

        .profile-brand h1 {
            font-size: 20px;
            margin: 0;
        }

        .profile-brand p {
            margin: 2px 0 0;
            font-size: 12.5px;
            color: var(--muted);
        }

        .profile-id-box {
            text-align: right;
        }

        .profile-id-box .profile-label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            color: var(--muted);
        }

        .profile-id-box .profile-value {
            font-size: 18px;
            font-weight: 800;
            color: var(--teal-dark);
        }

        .profile-status-badge {
            display: inline-block;
            margin-top: 6px;
            padding: 3px 10px;
            border-radius: 999px;
            background: #eef1f4;
            font-size: 11.5px;
            font-weight: 700;
        }

        .profile-identity {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 26px;
        }

        .profile-avatar {
            width: 68px;
            height: 68px;
            border-radius: 50%;
            background: #e6fbf8;
            color: var(--teal-dark);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            font-weight: 800;
            flex-shrink: 0;
        }

        .profile-identity h2 {
            margin: 0 0 4px;
            font-size: 19px;
        }

        .profile-identity p {
            margin: 0;
            font-size: 13px;
            color: var(--muted);
        }

        .profile-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px 24px;
            margin-bottom: 24px;
        }

        .profile-item .profile-item-label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            color: var(--muted);
            margin-bottom: 3px;
        }

        .profile-item .profile-item-value {
            font-size: 14.5px;
            font-weight: 600;
        }

        .profile-section-title {
            font-size: 13.5px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            color: var(--muted);
            margin: 26px 0 10px;
            border-bottom: 1px solid var(--border);
            padding-bottom: 6px;
        }

        table.profile-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 24px;
        }

        table.profile-table th,
        table.profile-table td {
            border: 1px solid var(--border);
            padding: 10px 12px;
            text-align: left;
            font-size: 13.5px;
        }

        table.profile-table th {
            background: #f4f8f9;
            font-size: 11.5px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            color: var(--muted);
            width: 34%;
        }

        .profile-footer {
            margin-top: 40px;
            display: flex;
            justify-content: space-between;
            font-size: 12.5px;
            color: var(--muted);
        }

        .profile-sign-section {
            margin-top: 56px;
            display: flex;
            justify-content: space-between;
            gap: 40px;
        }

        .profile-sign-line {
            flex: 1;
            border-top: 1px solid var(--text);
            padding-top: 6px;
            font-size: 12px;
        }

        .toolbar-btn {
            display: inline-block;
            margin-bottom: 20px;
            margin-right: 8px;
            padding: 10px 18px;
            background: var(--teal);
            color: #fff;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 13.5px;
            cursor: pointer;
        }

        .toolbar-btn.secondary {
            background: #eef1f4;
            color: var(--text);
        }

        @media print {

            .toolbar-btn {
                display: none;
            }

            body {
                margin: 0;
                padding: 0 12px;
            }
        }
    </style>
</head>

<body>

    <!-- Print/Download toolbar. "Download" reuses the browser's native
         print-to-PDF flow (same UI-only approach as print-purchase-order.php)
         since there is no backend PDF generator for this module yet. -->
    <button class="toolbar-btn" onclick="window.print()">Print this Profile</button>
    <button class="toolbar-btn secondary" onclick="window.print()">Download as PDF</button>

    <div class="profile-header">
        <div class="profile-brand">
            <img src="../logo-icon.png" alt="GabayMed logo">
            <div>
                <h1>GabayMed Hospital</h1>
                <p>User Management — User Profile</p>
            </div>
        </div>
        <div class="profile-id-box">
            <div class="profile-label">User ID</div>
            <div class="profile-value">#<?php echo (int) $user['id']; ?></div>
            <span class="profile-status-badge"><?php echo htmlspecialchars($user['status_label']); ?></span>
        </div>
    </div>

    <div class="profile-identity">
        <div class="profile-avatar"><?php echo htmlspecialchars($user['initials']); ?></div>
        <div>
            <h2><?php echo htmlspecialchars($user['full_name']); ?></h2>
            <p><?php echo htmlspecialchars($user['role_label']); ?> · <?php echo htmlspecialchars($user['department']); ?></p>
        </div>
    </div>

    <div class="profile-grid">
        <div class="profile-item">
            <div class="profile-item-label">Email</div>
            <div class="profile-item-value"><?php echo htmlspecialchars($user['email']); ?></div>
        </div>
        <div class="profile-item">
            <div class="profile-item-label">Phone</div>
            <div class="profile-item-value"><?php echo htmlspecialchars($user['phone']); ?></div>
        </div>
        <div class="profile-item">
            <div class="profile-item-label">Department</div>
            <div class="profile-item-value"><?php echo htmlspecialchars($user['department']); ?></div>
        </div>
        <div class="profile-item">
            <div class="profile-item-label">Status</div>
            <div class="profile-item-value"><?php echo htmlspecialchars($user['status_label']); ?></div>
        </div>
        <div class="profile-item">
            <div class="profile-item-label">Date Registered</div>
            <div class="profile-item-value"><?php echo htmlspecialchars(date('F j, Y', strtotime($user['date_registered']))); ?></div>
        </div>
        <div class="profile-item">
            <div class="profile-item-label">Last Login</div>
            <div class="profile-item-value"><?php echo htmlspecialchars($user['last_login']); ?></div>
        </div>
    </div>

    <div class="profile-section-title">Personal &amp; Account Details</div>
    <table class="profile-table">
        <tbody>
            <tr>
                <th>Username</th>
                <td><?php echo htmlspecialchars($user['username']); ?></td>
            </tr>
            <tr>
                <th>Gender</th>
                <td><?php echo htmlspecialchars($user['gender']); ?></td>
            </tr>
            <tr>
                <th>Birthdate</th>
                <td><?php echo htmlspecialchars(date('F j, Y', strtotime($user['birthdate']))); ?></td>
            </tr>
            <tr>
                <th>Address</th>
                <td><?php echo htmlspecialchars($user['address']); ?></td>
            </tr>
        </tbody>
    </table>

    <div class="profile-sign-section">
        <div class="profile-sign-line">Prepared by (Admin Signature)</div>
        <div class="profile-sign-line">Verified by (HR / Department Head)</div>
    </div>

    <div class="profile-footer">
        <span>GabayMed Hospital Management System</span>
        <span>Generated <?php echo date('F j, Y g:i A'); ?></span>
    </div>

</body>

</html>
