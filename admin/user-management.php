<?php
// admin/user-management.php
// Module — User Management (UI ONLY).
//
// Per the brief: no backend logic, no SQL, no CRUD beyond the existing
// auth guard. All data comes from includes/user-management-data.php as
// static placeholder records — see the TODO(backend) comments there for
// the real query this will become. Every action in this page (Add/Edit,
// Reset Password, Block, Deactivate, Delete) only updates the DOM and
// shows a toast; nothing is persisted. Swap those in for real requests
// once the users API exists, the same way inventory-procurement.php is
// structured to be upgraded later.

require_once '../includes/auth_guard.php';
require_role('admin');
require_once 'includes/user-management-data.php';

$today = date("F j, Y");

// ---- Stat cards ----
$totalUsers = count($placeholderUsers);
$countByRole = ['patient' => 0, 'doctor' => 0, 'pharmacist' => 0, 'admin' => 0, 'staff' => 0];
$countByStatus = ['active' => 0, 'inactive' => 0, 'blocked' => 0, 'pending' => 0, 'deactivated' => 0];
foreach ($placeholderUsers as $u) {
    $countByRole[$u['role']] = ($countByRole[$u['role']] ?? 0) + 1;
    $countByStatus[$u['status']] = ($countByStatus[$u['status']] ?? 0) + 1;
}

$stats = [
    ['label' => 'Total Users',       'value' => $totalUsers,                 'icon' => 'users',   'accent' => 'teal'],
    ['label' => 'Patients',          'value' => $countByRole['patient'],     'icon' => 'user',    'accent' => 'blue'],
    ['label' => 'Doctors',           'value' => $countByRole['doctor'],      'icon' => 'user-md', 'accent' => 'purple'],
    ['label' => 'Pharmacists',       'value' => $countByRole['pharmacist'],  'icon' => 'box',     'accent' => 'green'],
    ['label' => 'Hospital Staff',    'value' => $countByRole['staff'],       'icon' => 'user',    'accent' => 'amber'],
    ['label' => 'Active Accounts',   'value' => $countByStatus['active'],    'icon' => 'check',   'accent' => 'teal'],
];

$departments = array_values(array_unique(array_column($placeholderUsers, 'department')));
sort($departments);

// ---- Role distribution (percent of total, for the mock progress bars) ----
$roleDistribution = [];
foreach ($countByRole as $role => $count) {
    $roleDistribution[] = [
        'label' => $roleLabels[$role] ?? ucfirst($role),
        'count' => $count,
        'pct' => $totalUsers > 0 ? round(($count / $totalUsers) * 100) : 0,
    ];
}

// ---- Account status overview (donut = active vs everything else) ----
$activePct = $totalUsers > 0 ? round(($countByStatus['active'] / $totalUsers) * 100) : 0;

// ---- Recent user activity (module-wide feed — separate from each user's
// own per-record activity shown in the drawer) ----
// TODO(backend): pull from an audit-log table, most recent first.
$recentActivity = [
    ['text' => 'Erika Mateo created a Hospital Staff account for Grace Tolentino', 'time' => '20 minutes ago'],
    ['text' => 'Rosario Padilla logged in from Chrome on Windows', 'time' => '1 hour ago'],
    ['text' => 'Maria Santos was blocked for repeated missed appointments', 'time' => '3 hours ago'],
    ['text' => 'Dr. Carlo Villanueva account created — pending verification', 'time' => '5 hours ago'],
    ['text' => 'Tomas Aguilar was deactivated following resignation', 'time' => 'Jan 12, 2026'],
];

// TODO(backend): pull from a login_history / auth_log table.
$loginHistory = [
    ['name' => 'Ramon Santos', 'role' => 'Doctor', 'device' => 'Chrome · Windows', 'ip' => '192.168.1.24', 'time' => 'Jul 15, 2026 — 7:58 AM', 'result' => 'success'],
    ['name' => 'Rosario Padilla', 'role' => 'Pharmacist', 'device' => 'Edge · Windows', 'ip' => '192.168.1.31', 'time' => 'Jul 15, 2026 — 6:50 AM', 'result' => 'success'],
    ['name' => 'Maria Santos', 'role' => 'Patient', 'device' => 'Safari · iOS', 'ip' => '10.0.0.14', 'time' => 'Jul 14, 2026 — 11:42 PM', 'result' => 'failed'],
    ['name' => 'Erika Mateo', 'role' => 'Administrator', 'device' => 'Chrome · macOS', 'ip' => '192.168.1.5', 'time' => 'Jul 15, 2026 — 8:01 AM', 'result' => 'success'],
    ['name' => 'Grace Tolentino', 'role' => 'Hospital Staff', 'device' => 'Chrome · Windows', 'ip' => '192.168.1.40', 'time' => 'Jul 15, 2026 — 7:40 AM', 'result' => 'success'],
];

$tabs = [
    ['role' => 'all',        'label' => 'All Users'],
    ['role' => 'patient',    'label' => 'Patients'],
    ['role' => 'doctor',     'label' => 'Doctors'],
    ['role' => 'pharmacist', 'label' => 'Pharmacists'],
    ['role' => 'admin',      'label' => 'Administrators'],
    ['role' => 'staff',      'label' => 'Hospital Staff'],
];

$current_page = 'user-management';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - GabayMed</title>
    <link rel="stylesheet" href="../assets/css/doctor-dashboard.css">
    <link rel="stylesheet" href="../assets/css/admin-dashboard.css">
    <link rel="stylesheet" href="../assets/css/user-management.css">
</head>

<body>

    <div class="app-shell">

        <?php include 'includes/sidebar.php'; ?>

        <main class="main-content">

            <header class="page-header">
                <div>
                    <h1>User Management</h1>
                    <p class="page-subtitle">Manage hospital users.</p>
                </div>
                <div class="header-actions um-header-actions">
                    <span class="header-date"><?php echo htmlspecialchars($today); ?></span>
                    <button class="btn btn-secondary" type="button" id="umExportBtn">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><path d="M7 10l5 5 5-5"></path><path d="M12 15V3"></path></svg>
                        Export Users
                    </button>
                    <button class="btn-refresh" type="button" id="umRefreshBtn" aria-label="Refresh">
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"></path><path d="M3 21v-5h5"></path><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"></path><path d="M16 8h5V3"></path></svg>
                    </button>
                    <button class="btn btn-primary" type="button" id="umAddUserBtn">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="8.5" cy="7" r="4"></circle><line x1="20" y1="8" x2="20" y2="14"></line><line x1="23" y1="11" x2="17" y2="11"></line></svg>
                        Add User
                    </button>
                </div>
            </header>

            <!-- Stats -->
            <section class="stats-grid-6">
                <?php foreach ($stats as $stat): ?>
                    <div class="stat-card stat-<?php echo htmlspecialchars($stat['accent']); ?> fade-in-card">
                        <div class="stat-icon">
                            <?php if ($stat['icon'] === 'users'): ?>
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                            <?php elseif ($stat['icon'] === 'user-md'): ?>
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 8v3a7 7 0 0 1-14 0V8"></path><line x1="12" y1="18" x2="12" y2="22"></line><line x1="8" y1="22" x2="16" y2="22"></line><path d="M12 2a3 3 0 0 0-3 3v3a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3z"></path></svg>
                            <?php elseif ($stat['icon'] === 'user'): ?>
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                            <?php elseif ($stat['icon'] === 'box'): ?>
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8v13H3V8"></path><path d="M1 3h22v5H1z"></path><path d="M10 12h4"></path></svg>
                            <?php else: // check ?>
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                            <?php endif; ?>
                        </div>
                        <div class="stat-info">
                            <span class="stat-value"><?php echo htmlspecialchars($stat['value']); ?></span>
                            <span class="stat-label"><?php echo htmlspecialchars($stat['label']); ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </section>

            <!-- Users table card -->
            <section class="card">

                <div class="tabs-container um-tabs">
                    <div class="tabs-nav">
                        <?php foreach ($tabs as $i => $tab): ?>
                            <button type="button" class="tab-btn <?php echo $i === 0 ? 'active' : ''; ?>" data-role="<?php echo htmlspecialchars($tab['role']); ?>">
                                <?php echo htmlspecialchars($tab['label']); ?>
                                <span class="um-tab-count"><?php echo $tab['role'] === 'all' ? $totalUsers : ($countByRole[$tab['role']] ?? 0); ?></span>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="um-toolbar">
                    <div class="search-field">
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                        <input type="text" id="umSearchInput" placeholder="Search name, email, or phone...">
                    </div>

                    <div class="um-toolbar-filters">
                        <select id="umRoleFilter" class="filter-select" aria-label="Role">
                            <option value="all">All Roles</option>
                            <option value="patient">Patient</option>
                            <option value="doctor">Doctor</option>
                            <option value="pharmacist">Pharmacist</option>
                            <option value="admin">Administrator</option>
                            <option value="staff">Hospital Staff</option>
                        </select>

                        <select id="umDepartmentFilter" class="filter-select" aria-label="Department">
                            <option value="all">All Departments</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?php echo htmlspecialchars($dept); ?>"><?php echo htmlspecialchars($dept); ?></option>
                            <?php endforeach; ?>
                        </select>

                        <select id="umStatusFilter" class="filter-select" aria-label="Status">
                            <option value="all">All Statuses</option>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                            <option value="blocked">Blocked</option>
                            <option value="pending">Pending</option>
                            <option value="deactivated">Deactivated</option>
                        </select>

                        <input type="date" id="umDateFilter" class="filter-select" aria-label="Date Registered">

                        <select id="umSortSelect" class="filter-select" aria-label="Sort">
                            <option value="date-desc">Newest Registered</option>
                            <option value="date-asc">Oldest Registered</option>
                            <option value="name-asc">Name (A–Z)</option>
                            <option value="name-desc">Name (Z–A)</option>
                            <option value="status">Status</option>
                        </select>

                        <button class="btn btn-secondary" type="button" id="umResetFiltersBtn">Reset Filters</button>
                    </div>
                </div>

                <p class="card-subtitle" style="padding:0 20px;margin:-4px 0 4px;" id="umRowCount"><?php echo $totalUsers; ?> users</p>

                <?php if (count($placeholderUsers) > 0): ?>
                    <div class="table-wrap um-table-wrap" id="umTableWrap">
                        <table class="queue-table" id="umUserTable">
                            <thead>
                                <tr>
                                    <th>Profile</th>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Phone</th>
                                    <th>Role</th>
                                    <th>Department</th>
                                    <th>Status</th>
                                    <th>Last Login</th>
                                    <th style="text-align:right;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <!-- Skeleton rows: shown only while #umTableWrap has .is-loading (during Refresh) -->
                                <?php for ($s = 0; $s < 4; $s++): ?>
                                    <tr class="um-skeleton-row">
                                        <td><div class="skeleton" style="width:36px;height:36px;border-radius:50%;"></div></td>
                                        <td><div class="skeleton um-skeleton-line" style="width:120px;"></div></td>
                                        <td><div class="skeleton um-skeleton-line" style="width:150px;"></div></td>
                                        <td><div class="skeleton um-skeleton-line" style="width:100px;"></div></td>
                                        <td><div class="skeleton um-skeleton-line" style="width:70px;"></div></td>
                                        <td><div class="skeleton um-skeleton-line" style="width:90px;"></div></td>
                                        <td><div class="skeleton um-skeleton-line" style="width:60px;"></div></td>
                                        <td><div class="skeleton um-skeleton-line" style="width:90px;"></div></td>
                                        <td><div class="skeleton um-skeleton-line" style="width:60px;margin-left:auto;"></div></td>
                                    </tr>
                                <?php endfor; ?>

                                <?php foreach ($placeholderUsers as $u): ?>
                                    <tr class="um-row"
                                        tabindex="0"
                                        data-user-id="<?php echo (int) $u['id']; ?>"
                                        data-role="<?php echo htmlspecialchars($u['role']); ?>"
                                        data-department="<?php echo htmlspecialchars($u['department']); ?>"
                                        data-status="<?php echo htmlspecialchars($u['status']); ?>"
                                        data-date="<?php echo htmlspecialchars($u['date_registered']); ?>"
                                        data-name="<?php echo htmlspecialchars(strtolower($u['full_name'])); ?>"
                                        data-search="<?php echo htmlspecialchars(strtolower($u['full_name'] . ' ' . $u['email'] . ' ' . $u['phone'])); ?>">
                                        <td>
                                            <div class="um-avatar um-avatar-<?php echo htmlspecialchars($u['role']); ?>"><?php echo htmlspecialchars($u['initials']); ?></div>
                                        </td>
                                        <td>
                                            <div class="um-name-cell">
                                                <strong><?php echo htmlspecialchars($u['full_name']); ?></strong>
                                                <span>@<?php echo htmlspecialchars($u['username']); ?></span>
                                            </div>
                                        </td>
                                        <td><?php echo htmlspecialchars($u['email']); ?></td>
                                        <td><?php echo htmlspecialchars($u['phone']); ?></td>
                                        <td><?php echo htmlspecialchars($u['role_label']); ?></td>
                                        <td><?php echo htmlspecialchars($u['department']); ?></td>
                                        <td>
                                            <span class="status-pill um-status-pill status-um-<?php echo htmlspecialchars($u['status']); ?>">
                                                <?php echo htmlspecialchars($u['status_label']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($u['last_login']); ?></td>
                                        <td>
                                            <div class="um-row-actions">
                                                <button type="button" class="btn-row-action" data-um-action="view">View</button>
                                                <button type="button" class="btn-row-action" data-um-action="edit">Edit</button>
                                                <div class="um-kebab-wrap">
                                                    <button type="button" class="um-kebab-btn" aria-label="More actions" aria-haspopup="true">
                                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="5" r="1"></circle><circle cx="12" cy="12" r="1"></circle><circle cx="12" cy="19" r="1"></circle></svg>
                                                    </button>
                                                    <div class="um-kebab-menu">
                                                        <button type="button" data-um-action="reset">Reset Password</button>
                                                        <button type="button" data-um-action="block">Block User</button>
                                                        <button type="button" data-um-action="deactivate">Deactivate User</button>
                                                        <hr>
                                                        <button type="button" class="um-danger" data-um-action="delete">Delete User</button>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                        <div class="no-results" id="umNoResults" hidden>
                            <p>No users match your search or filters.</p>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-illustration">
                            <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle></svg>
                        </div>
                        <p>No hospital users yet.</p>
                        <button class="btn btn-primary" type="button" id="umEmptyAddUserBtn">Add User</button>
                    </div>
                <?php endif; ?>
            </section>

            <!-- Bottom section: activity, login history, distribution, status overview -->
            <section class="um-bottom-grid">
                <div class="card um-bottom-grid-full">
                    <div class="card-header">
                        <h2>Recent User Activity</h2>
                        <span class="card-subtitle">Module-wide feed</span>
                    </div>
                    <ul class="activity-timeline">
                        <?php foreach ($recentActivity as $item): ?>
                            <li class="activity-item">
                                <span class="activity-dot"></span>
                                <div class="activity-body">
                                    <p><?php echo htmlspecialchars($item['text']); ?></p>
                                    <span class="activity-time"><?php echo htmlspecialchars($item['time']); ?></span>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <div class="card um-bottom-grid-full">
                    <div class="card-header">
                        <h2>Login History</h2>
                        <span class="card-subtitle">Most recent sign-ins</span>
                    </div>
                    <div class="table-wrap">
                        <table class="queue-table">
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Role</th>
                                    <th>Device</th>
                                    <th>IP Address</th>
                                    <th>Date &amp; Time</th>
                                    <th>Result</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($loginHistory as $log): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($log['name']); ?></td>
                                        <td><?php echo htmlspecialchars($log['role']); ?></td>
                                        <td><?php echo htmlspecialchars($log['device']); ?></td>
                                        <td><?php echo htmlspecialchars($log['ip']); ?></td>
                                        <td><?php echo htmlspecialchars($log['time']); ?></td>
                                        <td>
                                            <span class="status-pill <?php echo $log['result'] === 'success' ? 'status-um-active' : 'status-um-blocked'; ?>">
                                                <?php echo $log['result'] === 'success' ? 'Success' : 'Failed'; ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h2>Role Distribution</h2>
                        <span class="card-subtitle">Share of total users</span>
                    </div>
                    <div class="mock-progress-list">
                        <?php foreach ($roleDistribution as $r): ?>
                            <div>
                                <span><?php echo htmlspecialchars($r['label']); ?></span>
                                <strong><?php echo (int) $r['pct']; ?>%</strong>
                                <i style="width:<?php echo (int) $r['pct']; ?>%"></i>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h2>Account Status Overview</h2>
                        <span class="card-subtitle">Active vs. other statuses</span>
                    </div>
                    <div class="mock-donut-chart" style="background: conic-gradient(var(--teal) 0 <?php echo (int) $activePct; ?>%, var(--blue-light) <?php echo (int) $activePct; ?>% 100%);">
                        <span><?php echo (int) $activePct; ?>%</span>
                    </div>
                    <div class="um-donut-legend">
                        <span><i style="background:var(--teal);"></i>Active (<?php echo $countByStatus['active']; ?>)</span>
                        <span><i style="background:var(--amber);"></i>Pending (<?php echo $countByStatus['pending']; ?>)</span>
                        <span><i style="background:var(--red);"></i>Blocked (<?php echo $countByStatus['blocked']; ?>)</span>
                        <span><i style="background:var(--purple);"></i>Deactivated (<?php echo $countByStatus['deactivated']; ?>)</span>
                        <span><i style="background:#c7ccd3;"></i>Inactive (<?php echo $countByStatus['inactive']; ?>)</span>
                    </div>
                </div>
            </section>

            <footer class="page-footer">
                <p>&copy; <?php echo date("Y"); ?> GabayMed Hospital Management System</p>
            </footer>

        </main>
    </div>

    <!-- ===================== Right-side drawer: View User ===================== -->
    <div class="drawer-backdrop" id="umDrawerBackdrop">
        <aside class="drawer-panel" role="dialog" aria-modal="true" aria-labelledby="umDrawerName">
            <div class="drawer-header">
                <div class="drawer-header-identity">
                    <div class="drawer-avatar" id="umDrawerAvatar"></div>
                    <div>
                        <p class="drawer-header-name" id="umDrawerName"></p>
                        <p class="drawer-header-sub">
                            <span id="umDrawerSub"></span>
                            <span class="status-pill" id="umDrawerStatus"></span>
                        </p>
                    </div>
                </div>
                <div class="drawer-header-actions">
                    <button type="button" class="drawer-icon-btn" id="umDrawerPrintBtn" aria-label="Print profile" title="Print profile (PDF-style preview)">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
                    </button>
                    <button type="button" class="drawer-close-btn" id="umDrawerCloseBtn" aria-label="Close panel">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                    </button>
                </div>
            </div>
            <div class="drawer-body">
                <div class="drawer-section">
                    <p class="drawer-section-title">Personal Information</p>
                    <div class="drawer-info-row"><span class="drawer-info-key">Gender</span><span class="drawer-info-value" id="umDrawerGender"></span></div>
                    <div class="drawer-info-row"><span class="drawer-info-key">Birthdate</span><span class="drawer-info-value" id="umDrawerBirthdate"></span></div>
                    <div class="drawer-info-row"><span class="drawer-info-key">Address</span><span class="drawer-info-value" id="umDrawerAddress"></span></div>
                </div>

                <div class="drawer-section">
                    <p class="drawer-section-title">Contact Information</p>
                    <div class="drawer-info-row"><span class="drawer-info-key">Email</span><span class="drawer-info-value" id="umDrawerEmail"></span></div>
                    <div class="drawer-info-row"><span class="drawer-info-key">Phone</span><span class="drawer-info-value" id="umDrawerPhone"></span></div>
                </div>

                <div class="drawer-section">
                    <p class="drawer-section-title">Account Information</p>
                    <div class="drawer-info-row"><span class="drawer-info-key">Username</span><span class="drawer-info-value" id="umDrawerUsername"></span></div>
                    <div class="drawer-info-row"><span class="drawer-info-key">Role</span><span class="drawer-info-value" id="umDrawerRole"></span></div>
                    <div class="drawer-info-row"><span class="drawer-info-key">Department</span><span class="drawer-info-value" id="umDrawerDepartment"></span></div>
                    <div class="drawer-info-row"><span class="drawer-info-key">Date Registered</span><span class="drawer-info-value" id="umDrawerRegistered"></span></div>
                    <div class="drawer-info-row"><span class="drawer-info-key">Last Login</span><span class="drawer-info-value" id="umDrawerLastLogin"></span></div>
                </div>

                <div class="drawer-section">
                    <p class="drawer-section-title">Recent Activity</p>
                    <ul class="activity-timeline" id="umDrawerActivity"></ul>
                </div>
            </div>
            <div class="drawer-footer">
                <button type="button" class="btn btn-secondary" id="umDrawerResetBtn">Reset Password</button>
                <button type="button" class="btn btn-primary" id="umDrawerEditBtn">Edit</button>
            </div>
        </aside>
    </div>

    <!-- ===================== MODAL: Add / Edit User (reusable) ===================== -->
    <div class="modal-backdrop" id="umUserModal" aria-hidden="true">
        <div class="modal-box um-modal-box" role="dialog" aria-modal="true" aria-labelledby="umUserModalTitle">
            <div class="um-modal-header">
                <h3 id="umUserModalTitle">Add User</h3>
                <button type="button" class="um-modal-close" data-close-modal="umUserModal" aria-label="Close">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                </button>
            </div>
            <form id="umUserForm" class="um-form">
                <div class="um-form-section">
                    <p class="um-form-section-title">Personal Information</p>
                    <div class="um-form-row">
                        <label class="um-field"><span>First Name</span><input type="text" name="first_name" placeholder="Juan" required></label>
                        <label class="um-field"><span>Last Name</span><input type="text" name="last_name" placeholder="Dela Cruz" required></label>
                    </div>
                    <div class="um-form-row" style="margin-top:14px;">
                        <label class="um-field"><span>Gender</span>
                            <select name="gender">
                                <option value="">Select…</option>
                                <option>Male</option>
                                <option>Female</option>
                            </select>
                        </label>
                        <label class="um-field"><span>Birthdate</span><input type="date" name="birthdate"></label>
                    </div>
                </div>

                <div class="um-form-section">
                    <p class="um-form-section-title">Account Information</p>
                    <div class="um-form-row">
                        <label class="um-field"><span>Email</span><input type="email" name="email" placeholder="juan.delacruz@gabaymed.local" required></label>
                        <label class="um-field"><span>Phone</span><input type="tel" name="phone" placeholder="0917 123 4567"></label>
                    </div>
                    <div class="um-form-row" style="margin-top:14px;">
                        <label class="um-field"><span>Username</span><input type="text" name="username" placeholder="jdelacruz" required></label>
                        <label class="um-field"><span>Temporary Password</span><input type="password" name="password" placeholder="••••••••"></label>
                    </div>
                </div>

                <div class="um-form-section">
                    <p class="um-form-section-title">Role &amp; Assignment</p>
                    <div class="um-form-row">
                        <label class="um-field"><span>Role</span>
                            <select name="role" required>
                                <option value="patient">Patient</option>
                                <option value="doctor">Doctor</option>
                                <option value="pharmacist">Pharmacist</option>
                                <option value="admin">Administrator</option>
                                <option value="staff">Hospital Staff</option>
                            </select>
                        </label>
                        <label class="um-field"><span>Department</span>
                            <select name="department">
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?php echo htmlspecialchars($dept); ?>"><?php echo htmlspecialchars($dept); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </div>
                    <label class="um-field" style="margin-top:14px;"><span>Status</span>
                        <select name="status">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                            <option value="blocked">Blocked</option>
                            <option value="pending">Pending</option>
                            <option value="deactivated">Deactivated</option>
                        </select>
                    </label>
                </div>

                <div class="um-modal-actions">
                    <button type="button" class="btn btn-secondary" data-close-modal="umUserModal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="umUserModalSubmitBtn">Create User</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ===================== MODAL: Reset Password (confirm) ===================== -->
    <div class="modal-backdrop" id="umResetPasswordModal" aria-hidden="true">
        <div class="modal-box um-modal-box um-modal-box-sm" role="dialog" aria-modal="true">
            <div class="um-modal-header">
                <h3>Reset Password</h3>
                <button type="button" class="um-modal-close" data-close-modal="umResetPasswordModal" aria-label="Close">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                </button>
            </div>
            <div class="um-confirm-body">
                <div class="um-confirm-icon um-confirm-warn">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                </div>
                <p>Send a password reset link to:</p>
                <div class="um-confirm-user-box">
                    <div class="um-avatar um-confirm-user-avatar"></div>
                    <div>
                        <strong class="um-confirm-user-name"></strong>
                        <div class="drawer-header-sub um-confirm-user-sub"></div>
                    </div>
                </div>
            </div>
            <div class="um-modal-actions" style="padding:16px 22px 22px;">
                <button type="button" class="btn btn-secondary" data-close-modal="umResetPasswordModal">Cancel</button>
                <button type="button" class="btn btn-primary" data-confirm-action="reset">Send Reset Link</button>
            </div>
        </div>
    </div>

    <!-- ===================== MODAL: Block User (confirm) ===================== -->
    <div class="modal-backdrop" id="umBlockModal" aria-hidden="true">
        <div class="modal-box um-modal-box um-modal-box-sm" role="dialog" aria-modal="true">
            <div class="um-modal-header">
                <h3>Block User</h3>
                <button type="button" class="um-modal-close" data-close-modal="umBlockModal" aria-label="Close">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                </button>
            </div>
            <div class="um-confirm-body">
                <div class="um-confirm-icon um-confirm-danger">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"></line></svg>
                </div>
                <p>This user will lose access to their account immediately. Are you sure you want to block:</p>
                <div class="um-confirm-user-box">
                    <div class="um-avatar um-confirm-user-avatar"></div>
                    <div>
                        <strong class="um-confirm-user-name"></strong>
                        <div class="drawer-header-sub um-confirm-user-sub"></div>
                    </div>
                </div>
            </div>
            <div class="um-modal-actions" style="padding:16px 22px 22px;">
                <button type="button" class="btn btn-secondary" data-close-modal="umBlockModal">Cancel</button>
                <button type="button" class="btn btn-primary" style="background:var(--red);" data-confirm-action="block">Block User</button>
            </div>
        </div>
    </div>

    <!-- ===================== MODAL: Deactivate User (confirm) ===================== -->
    <div class="modal-backdrop" id="umDeactivateModal" aria-hidden="true">
        <div class="modal-box um-modal-box um-modal-box-sm" role="dialog" aria-modal="true">
            <div class="um-modal-header">
                <h3>Deactivate User</h3>
                <button type="button" class="um-modal-close" data-close-modal="umDeactivateModal" aria-label="Close">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                </button>
            </div>
            <div class="um-confirm-body">
                <div class="um-confirm-icon um-confirm-warn">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18.36 6.64a9 9 0 1 1-12.73 0"></path><line x1="12" y1="2" x2="12" y2="12"></line></svg>
                </div>
                <p>The account will be marked deactivated and hidden from active rosters. Continue for:</p>
                <div class="um-confirm-user-box">
                    <div class="um-avatar um-confirm-user-avatar"></div>
                    <div>
                        <strong class="um-confirm-user-name"></strong>
                        <div class="drawer-header-sub um-confirm-user-sub"></div>
                    </div>
                </div>
            </div>
            <div class="um-modal-actions" style="padding:16px 22px 22px;">
                <button type="button" class="btn btn-secondary" data-close-modal="umDeactivateModal">Cancel</button>
                <button type="button" class="btn btn-primary" data-confirm-action="deactivate">Deactivate User</button>
            </div>
        </div>
    </div>

    <!-- ===================== MODAL: Delete User (confirm) ===================== -->
    <div class="modal-backdrop" id="umDeleteModal" aria-hidden="true">
        <div class="modal-box um-modal-box um-modal-box-sm" role="dialog" aria-modal="true">
            <div class="um-modal-header">
                <h3>Delete User</h3>
                <button type="button" class="um-modal-close" data-close-modal="umDeleteModal" aria-label="Close">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                </button>
            </div>
            <div class="um-confirm-body">
                <div class="um-confirm-icon um-confirm-danger">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"></path><path d="M10 11v6"></path><path d="M14 11v6"></path></svg>
                </div>
                <p><strong>This action cannot be undone.</strong> This will permanently remove the account and all associated records for:</p>
                <div class="um-confirm-user-box">
                    <div class="um-avatar um-confirm-user-avatar"></div>
                    <div>
                        <strong class="um-confirm-user-name"></strong>
                        <div class="drawer-header-sub um-confirm-user-sub"></div>
                    </div>
                </div>
            </div>
            <div class="um-modal-actions" style="padding:16px 22px 22px;">
                <button type="button" class="btn btn-secondary" data-close-modal="umDeleteModal">Cancel</button>
                <button type="button" class="btn btn-primary" style="background:var(--red);" data-confirm-action="delete">Delete User</button>
            </div>
        </div>
    </div>

    <script>
        // Same placeholder data the table above was rendered from, re-exposed
        // as JSON so the drawer/modals/export don't need a second round trip
        // — same pattern as hospital-census.php's `censusData`.
        const umUsers = <?php echo json_encode(array_combine(
                            array_column($placeholderUsers, 'id'),
                            $placeholderUsers
                        )); ?>;
    </script>
    <script src="../assets/js/user-management.js" defer></script>

    <?php if (count($placeholderUsers) === 0): ?>
    <script>
        document.getElementById('umEmptyAddUserBtn')?.addEventListener('click', function () {
            document.getElementById('umAddUserBtn')?.click();
        });
    </script>
    <?php endif; ?>

</body>

</html>
