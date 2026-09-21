<?php
// admin/user-management.php
// Module — User Management.
//
// Real, DB-backed view over admin/includes/user-management-data.php
// (um_fetch_users / um_fetch_user) and posts to
// admin/user-management-actions.php for every mutation (create_user,
// update_user, toggle_active, set_status, reset_password, archive_user,
// restore_user). There is no username column and no login-history table
// in the real schema, so those are gone from this page entirely.
//
// Archived users are excluded from the normal list by um_fetch_users();
// this page exposes them via a simple ?view=archived toggle that
// re-queries with $archivedOnly = true.

require_once '../includes/auth_guard.php';
require_role('admin');
require_once '../config/db.php';
require_once 'includes/user-management-data.php';

// CSRF token for every POST this page makes to user-management-actions.php.
// If something earlier in the app (e.g. login) already set one, reuse it;
// otherwise generate it here.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$today = date("F j, Y");

// ---- Departments (real table, not derived from user rows) ----
$departments = [];
$deptResult = $conn->query("SELECT department_id, department_name FROM departments WHERE is_active = 1 ORDER BY department_name");
if ($deptResult) {
    while ($deptRow = $deptResult->fetch_assoc()) {
        $departments[] = $deptRow;
    }
}

// ---- Which view: active roster (default) or archived ----
$currentView = (($_GET['view'] ?? '') === 'archived') ? 'archived' : 'active';

$activeUsers = um_fetch_users($conn, false);
$archivedUsers = um_fetch_users($conn, true);
$users = $currentView === 'archived' ? $archivedUsers : $activeUsers;

// ---- Stat cards always summarize the active roster, regardless of which
// view the table below is currently showing ----
$totalUsers = count($activeUsers);
$countByRole = ['patient' => 0, 'doctor' => 0, 'pharmacist' => 0, 'admin' => 0, 'staff' => 0];
$countByStatus = ['active' => 0, 'pending' => 0, 'blocked' => 0, 'confined' => 0, 'deceased' => 0, 'deactivated' => 0];
foreach ($activeUsers as $u) {
    $countByRole[$u['role']] = ($countByRole[$u['role']] ?? 0) + 1;
    $countByStatus[$u['status_key']] = ($countByStatus[$u['status_key']] ?? 0) + 1;
}

$stats = [
    ['label' => 'Total Users',       'value' => $totalUsers,                 'icon' => 'users',   'accent' => 'teal'],
    ['label' => 'Patients',          'value' => $countByRole['patient'],     'icon' => 'user',    'accent' => 'blue'],
    ['label' => 'Doctors',           'value' => $countByRole['doctor'],      'icon' => 'user-md', 'accent' => 'purple'],
    ['label' => 'Pharmacists',       'value' => $countByRole['pharmacist'],  'icon' => 'box',     'accent' => 'green'],
    ['label' => 'Hospital Staff',    'value' => $countByRole['staff'],       'icon' => 'user',    'accent' => 'amber'],
    ['label' => 'Active Accounts',   'value' => $countByStatus['active'],    'icon' => 'check',   'accent' => 'teal'],
];

// ---- Role distribution (percent of the active roster) ----
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

// ---- Recent user activity (module-wide feed) ----
// Real, DB-backed: sign-in/sign-out events from audit_log (module = 'auth',
// written by login.php / logout.php). Most recent first, capped at 8.
//
// TODO(backend): account-lifecycle events (created/blocked/deactivated/
// restored) aren't logged to audit_log yet — user-management-actions.php
// doesn't call write_audit_log() for any of its mutations. Once it does,
// UNION those in here the same way admin/dashboard.php does for its feed.
$recentActivity = [];
$activityResult = $conn->query(
    "SELECT CONCAT(u.first_name, ' ', u.last_name) AS full_name,
            u.role AS role,
            al.action AS action,
            al.logged_at AS event_time
     FROM audit_log al
     JOIN users u ON u.user_id = al.user_id
     WHERE al.module = 'auth'
     ORDER BY al.logged_at DESC
     LIMIT 8"
);
if ($activityResult) {
    while ($row = $activityResult->fetch_assoc()) {
        $verb = $row['action'] === 'login' ? 'signed in' : 'signed out';
        $roleLabel = $roleLabels[$row['role']] ?? ucfirst($row['role']);
        $recentActivity[] = [
            'text' => $row['full_name'] . ' (' . $roleLabel . ') ' . $verb,
            'time' => date('g:i A, M j', strtotime($row['event_time'])),
        ];
    }
}

// ---- Per-role counts for the CURRENT view (drives the tab badges) ----
$viewCountByRole = ['patient' => 0, 'doctor' => 0, 'pharmacist' => 0, 'admin' => 0, 'staff' => 0];
foreach ($users as $u) {
    $viewCountByRole[$u['role']] = ($viewCountByRole[$u['role']] ?? 0) + 1;
}
$viewTotal = count($users);

$tabs = [
    ['role' => 'all',        'label' => 'All Users'],
    ['role' => 'patient',    'label' => 'Patients'],
    ['role' => 'doctor',     'label' => 'Doctors'],
    ['role' => 'pharmacist', 'label' => 'Pharmacists'],
    ['role' => 'admin',      'label' => 'Administrators'],
    ['role' => 'staff',      'label' => 'Hospital Staff'],
];

$statusFilterOptions = [
    'active'      => 'Active',
    'pending'     => 'Pending',
    'blocked'     => 'Blocked',
    'confined'    => 'Confined',
    'deceased'    => 'Deceased',
    'deactivated' => 'Deactivated',
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

    <input type="hidden" id="umCsrfToken" value="<?php echo htmlspecialchars($csrfToken); ?>">

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
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                            <path d="M7 10l5 5 5-5"></path>
                            <path d="M12 15V3"></path>
                        </svg>
                        Export Users
                    </button>
                    <button class="btn-refresh" type="button" id="umRefreshBtn" aria-label="Refresh">
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"></path>
                            <path d="M3 21v-5h5"></path>
                            <path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"></path>
                            <path d="M16 8h5V3"></path>
                        </svg>
                    </button>
                    <button class="btn btn-primary" type="button" id="umAddUserBtn">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                            <circle cx="8.5" cy="7" r="4"></circle>
                            <line x1="20" y1="8" x2="20" y2="14"></line>
                            <line x1="23" y1="11" x2="17" y2="11"></line>
                        </svg>
                        Add User
                    </button>
                </div>
            </header>

            <!-- Stats (always reflect the active roster, not the current view) -->
            <section class="stats-grid-6">
                <?php foreach ($stats as $stat): ?>
                    <div class="stat-card stat-<?php echo htmlspecialchars($stat['accent']); ?> fade-in-card">
                        <div class="stat-icon">
                            <?php if ($stat['icon'] === 'users'): ?>
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                                    <circle cx="9" cy="7" r="4"></circle>
                                    <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                                    <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                                </svg>
                            <?php elseif ($stat['icon'] === 'user-md'): ?>
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M19 8v3a7 7 0 0 1-14 0V8"></path>
                                    <line x1="12" y1="18" x2="12" y2="22"></line>
                                    <line x1="8" y1="22" x2="16" y2="22"></line>
                                    <path d="M12 2a3 3 0 0 0-3 3v3a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3z"></path>
                                </svg>
                            <?php elseif ($stat['icon'] === 'user'): ?>
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                                    <circle cx="12" cy="7" r="4"></circle>
                                </svg>
                            <?php elseif ($stat['icon'] === 'box'): ?>
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M21 8v13H3V8"></path>
                                    <path d="M1 3h22v5H1z"></path>
                                    <path d="M10 12h4"></path>
                                </svg>
                            <?php else: // check 
                            ?>
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                                    <polyline points="22 4 12 14.01 9 11.01"></polyline>
                                </svg>
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
                                <span class="um-tab-count"><?php echo $tab['role'] === 'all' ? $viewTotal : ($viewCountByRole[$tab['role']] ?? 0); ?></span>
                            </button>
                        <?php endforeach; ?>
                    </div>
                    <div class="um-view-switch">
                        <a href="?view=active" class="tab-btn <?php echo $currentView === 'active' ? 'active' : ''; ?>">Active</a>
                        <a href="?view=archived" class="tab-btn <?php echo $currentView === 'archived' ? 'active' : ''; ?>">Archived<span class="um-tab-count"><?php echo count($archivedUsers); ?></span></a>
                    </div>
                </div>

                <div class="um-toolbar">
                    <div class="search-field">
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="11" cy="11" r="8"></circle>
                            <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                        </svg>
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
                            <option value="capitol">Provincial Capitol</option>
                        </select>

                        <select id="umDepartmentFilter" class="filter-select" aria-label="Department">
                            <option value="all">All Departments</option>
                            <option value="0">No Department</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?php echo (int) $dept['department_id']; ?>"><?php echo htmlspecialchars($dept['department_name']); ?></option>
                            <?php endforeach; ?>
                        </select>

                        <select id="umStatusFilter" class="filter-select" aria-label="Status">
                            <option value="all">All Statuses</option>
                            <?php foreach ($statusFilterOptions as $key => $label): ?>
                                <option value="<?php echo htmlspecialchars($key); ?>"><?php echo htmlspecialchars($label); ?></option>
                            <?php endforeach; ?>
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

                <p class="card-subtitle" style="padding:0 20px;margin:-4px 0 4px;" id="umRowCount"><?php echo $viewTotal; ?> users</p>

                <?php if (count($users) > 0): ?>
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
                                    <th>Date Registered</th>
                                    <th style="text-align:right;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <!-- Skeleton rows: shown only while #umTableWrap has .is-loading (during Refresh) -->
                                <?php for ($s = 0; $s < 4; $s++): ?>
                                    <tr class="um-skeleton-row">
                                        <td>
                                            <div class="skeleton" style="width:36px;height:36px;border-radius:50%;"></div>
                                        </td>
                                        <td>
                                            <div class="skeleton um-skeleton-line" style="width:120px;"></div>
                                        </td>
                                        <td>
                                            <div class="skeleton um-skeleton-line" style="width:150px;"></div>
                                        </td>
                                        <td>
                                            <div class="skeleton um-skeleton-line" style="width:100px;"></div>
                                        </td>
                                        <td>
                                            <div class="skeleton um-skeleton-line" style="width:70px;"></div>
                                        </td>
                                        <td>
                                            <div class="skeleton um-skeleton-line" style="width:90px;"></div>
                                        </td>
                                        <td>
                                            <div class="skeleton um-skeleton-line" style="width:60px;"></div>
                                        </td>
                                        <td>
                                            <div class="skeleton um-skeleton-line" style="width:90px;"></div>
                                        </td>
                                        <td>
                                            <div class="skeleton um-skeleton-line" style="width:60px;margin-left:auto;"></div>
                                        </td>
                                    </tr>
                                <?php endfor; ?>

                                <?php foreach ($users as $u):
                                    $regTs = strtotime($u['date_registered']);
                                    $regIso = $regTs ? date('Y-m-d', $regTs) : '';
                                    $regDisplay = $regTs ? date('M j, Y', $regTs) : '—';
                                ?>
                                    <tr class="um-row"
                                        tabindex="0"
                                        data-user-id="<?php echo (int) $u['id']; ?>"
                                        data-role="<?php echo htmlspecialchars($u['role']); ?>"
                                        data-department="<?php echo (int) ($u['department_id'] ?? 0); ?>"
                                        data-status="<?php echo htmlspecialchars($u['status_key']); ?>"
                                        data-date="<?php echo htmlspecialchars($regIso); ?>"
                                        data-name="<?php echo htmlspecialchars(strtolower($u['full_name'])); ?>"
                                        data-search="<?php echo htmlspecialchars(strtolower($u['full_name'] . ' ' . $u['email'] . ' ' . $u['phone'])); ?>">
                                        <td>
                                            <div class="um-avatar um-avatar-<?php echo htmlspecialchars($u['role']); ?>"><?php echo htmlspecialchars($u['initials']); ?></div>
                                        </td>
                                        <td>
                                            <div class="um-name-cell">
                                                <strong><?php echo htmlspecialchars($u['full_name']); ?></strong>
                                            </div>
                                        </td>
                                        <td><?php echo htmlspecialchars($u['email'] ?: '—'); ?></td>
                                        <td><?php echo htmlspecialchars($u['phone']); ?></td>
                                        <td><?php echo htmlspecialchars($u['role_label']); ?></td>
                                        <td><?php echo htmlspecialchars($u['department_name']); ?></td>
                                        <td>
                                            <span class="status-pill um-status-pill status-um-<?php echo htmlspecialchars($u['status_key']); ?>">
                                                <?php echo htmlspecialchars($u['status_label']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($regDisplay); ?></td>
                                        <td>
                                            <div class="um-row-actions">
                                                <button type="button" class="btn-row-action" data-um-action="view">View</button>
                                                <?php if ($currentView === 'archived'): ?>
                                                    <button type="button" class="btn-row-action" data-um-action="restore">Restore</button>
                                                <?php else: ?>
                                                    <button type="button" class="btn-row-action" data-um-action="edit">Edit</button>
                                                    <div class="um-kebab-wrap">
                                                        <button type="button" class="um-kebab-btn" aria-label="More actions" aria-haspopup="true">
                                                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                                <circle cx="12" cy="5" r="1"></circle>
                                                                <circle cx="12" cy="12" r="1"></circle>
                                                                <circle cx="12" cy="19" r="1"></circle>
                                                            </svg>
                                                        </button>
                                                        <div class="um-kebab-menu">
                                                            <button type="button" data-um-action="reset">Reset Password</button>
                                                            <button type="button" data-um-action="status" data-status-value="active">Mark Active</button>
                                                            <button type="button" data-um-action="status" data-status-value="blocked">Block User</button>
                                                            <button type="button" data-um-action="status" data-status-value="confined">Mark Confined</button>
                                                            <button type="button" data-um-action="status" data-status-value="deceased">Mark Deceased</button>
                                                            <button type="button" data-um-action="toggle-active">
                                                                <?php echo $u['is_active'] ? 'Deactivate User' : 'Reactivate User'; ?>
                                                            </button>
                                                            <hr>
                                                            <button type="button" class="um-danger" data-um-action="archive">Archive User</button>
                                                        </div>
                                                    </div>
                                                <?php endif; ?>
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
                            <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                                <circle cx="9" cy="7" r="4"></circle>
                            </svg>
                        </div>
                        <?php if ($currentView === 'archived'): ?>
                            <p>No archived users.</p>
                        <?php else: ?>
                            <p>No hospital users yet.</p>
                            <button class="btn btn-primary" type="button" id="umEmptyAddUserBtn">Add User</button>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </section>

            <!-- Bottom section: activity, distribution, status overview -->
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

                <div class="card">
                    <div class="card-header">
                        <h2>Role Distribution</h2>
                        <span class="card-subtitle">Share of active roster</span>
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
                        <span><i style="background:#c7ccd3;"></i>Confined (<?php echo $countByStatus['confined']; ?>)</span>
                        <span><i style="background:#8a8f98;"></i>Deceased (<?php echo $countByStatus['deceased']; ?>)</span>
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
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="6 9 6 2 18 2 18 9"></polyline>
                            <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path>
                            <rect x="6" y="14" width="12" height="8"></rect>
                        </svg>
                    </button>
                    <button type="button" class="drawer-close-btn" id="umDrawerCloseBtn" aria-label="Close panel">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="18" y1="6" x2="6" y2="18"></line>
                            <line x1="6" y1="6" x2="18" y2="18"></line>
                        </svg>
                    </button>
                </div>
            </div>
            <div class="drawer-body">
                <div class="drawer-section">
                    <p class="drawer-section-title">Personal Information</p>
                    <div class="drawer-info-row"><span class="drawer-info-key">Sex</span><span class="drawer-info-value" id="umDrawerGender"></span></div>
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
                    <div class="drawer-info-row"><span class="drawer-info-key">Role</span><span class="drawer-info-value" id="umDrawerRole"></span></div>
                    <div class="drawer-info-row"><span class="drawer-info-key">Department</span><span class="drawer-info-value" id="umDrawerDepartment"></span></div>
                    <div class="drawer-info-row"><span class="drawer-info-key">Date Registered</span><span class="drawer-info-value" id="umDrawerRegistered"></span></div>
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
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
            </div>
            <form id="umUserForm" class="um-form">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <input type="hidden" name="action" value="create_user">
                <input type="hidden" name="user_id" value="">
                <div class="um-form-error" id="umFormError" hidden></div>

                <div class="um-form-section">
                    <p class="um-form-section-title">Personal Information</p>
                    <div class="um-form-row">
                        <label class="um-field"><span>First Name</span><input type="text" name="first_name" placeholder="Juan" required></label>
                        <label class="um-field"><span>Last Name</span><input type="text" name="last_name" placeholder="Dela Cruz" required></label>
                    </div>
                    <div class="um-form-row" style="margin-top:14px;">
                        <label class="um-field"><span>Sex</span>
                            <select name="sex">
                                <option value="">Select…</option>
                                <option value="male">Male</option>
                                <option value="female">Female</option>
                            </select>
                        </label>
                        <label class="um-field"><span>Birthdate</span><input type="date" name="birthdate"></label>
                    </div>
                    <label class="um-field" style="margin-top:14px;"><span>Address</span><input type="text" name="address" placeholder="Optional"></label>
                </div>

                <div class="um-form-section">
                    <p class="um-form-section-title">Account Information</p>
                    <div class="um-form-row">
                        <label class="um-field"><span>Email</span><input type="email" name="email" placeholder="Optional"></label>
                        <label class="um-field"><span>Phone</span><input type="tel" name="phone" placeholder="0917 123 4567" required></label>
                    </div>
                    <div class="um-form-row um-form-row-password" style="margin-top:14px;">
                        <label class="um-field"><span>Temporary Password</span><input type="password" name="password" placeholder="At least 6 characters"></label>
                    </div>
                    <p class="drawer-info-key um-form-password-note" style="margin-top:6px;" hidden>Password changes go through "Reset Password" instead.</p>
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
                                <option value="capitol">Provincial Capitol</option>
                            </select>
                        </label>
                        <label class="um-field"><span>Department</span>
                            <select name="department_id">
                                <option value="">No Department</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?php echo (int) $dept['department_id']; ?>"><?php echo htmlspecialchars($dept['department_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </div>
                    <!-- Only meaningful for role=staff — Staff is one role but
                         two unrelated jobs (see 013_staff_subtype.sql):
                    <!-- Two unrelated jobs share the 'staff' role (013_staff_subtype.sql):
                         Front Desk (check-in/walk-in/schedule conflicts),
                         Inventory Counting, and Laboratory
                         (025_lab_order_queue_and_lab_staff_type.sql). JS
                         shows/hides this row based on the Role selector
                         above, same .hidden pattern already used for the
                         password row in edit mode. -->
                    <div class="um-form-row um-form-row-staff-type" hidden>
                        <label class="um-field"><span>Staff Type</span>
                            <select name="staff_type">
                                <option value="front_desk">Front Desk</option>
                                <option value="inventory">Inventory Counting</option>
                                <option value="laboratory">Laboratory</option>
                            </select>
                        </label>
                    </div>
                </div>

                <div class="um-modal-actions">
                    <button type="button" class="btn btn-secondary" data-close-modal="umUserModal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="umUserModalSubmitBtn">Create User</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ===================== MODAL: Reset Password (confirm + result) ===================== -->
    <div class="modal-backdrop" id="umResetPasswordModal" aria-hidden="true">
        <div class="modal-box um-modal-box um-modal-box-sm" role="dialog" aria-modal="true">
            <div class="um-modal-header">
                <h3>Reset Password</h3>
                <button type="button" class="um-modal-close" data-close-modal="umResetPasswordModal" aria-label="Close">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
            </div>
            <div class="um-confirm-body" id="umResetConfirmView">
                <div class="um-confirm-icon um-confirm-warn">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="11" width="18" height="11" rx="2"></rect>
                        <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                    </svg>
                </div>
                <p>Generate a new temporary password for:</p>
                <div class="um-confirm-user-box">
                    <div class="um-avatar um-confirm-user-avatar"></div>
                    <div>
                        <strong class="um-confirm-user-name"></strong>
                        <div class="drawer-header-sub um-confirm-user-sub"></div>
                    </div>
                </div>
            </div>
            <div class="um-confirm-body" id="umResetResultView" hidden>
                <p>Share this temporary password with the user directly. It will not be shown again.</p>
                <div class="um-form-row">
                    <input type="text" id="umResetTempPassword" readonly style="font-family:monospace;font-size:16px;letter-spacing:1px;">
                    <button type="button" class="btn btn-secondary" id="umResetCopyBtn">Copy</button>
                </div>
            </div>
            <div class="um-modal-actions" style="padding:16px 22px 22px;" id="umResetConfirmActions">
                <button type="button" class="btn btn-secondary" data-close-modal="umResetPasswordModal">Cancel</button>
                <button type="button" class="btn btn-primary" data-confirm-action="reset">Generate Password</button>
            </div>
            <div class="um-modal-actions" style="padding:16px 22px 22px;" id="umResetDoneActions" hidden>
                <button type="button" class="btn btn-primary" id="umResetDoneBtn">Done</button>
            </div>
        </div>
    </div>

    <!-- ===================== MODAL: Set Status (block / confine / mark deceased / reactivate) ===================== -->
    <div class="modal-backdrop" id="umSetStatusModal" aria-hidden="true">
        <div class="modal-box um-modal-box um-modal-box-sm" role="dialog" aria-modal="true">
            <div class="um-modal-header">
                <h3 id="umSetStatusTitle">Update Status</h3>
                <button type="button" class="um-modal-close" data-close-modal="umSetStatusModal" aria-label="Close">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
            </div>
            <div class="um-confirm-body">
                <div class="um-confirm-icon um-confirm-danger">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="4.93" y1="4.93" x2="19.07" y2="19.07"></line>
                    </svg>
                </div>
                <p id="umSetStatusCopy">Update this user's status:</p>
                <div class="um-confirm-user-box">
                    <div class="um-avatar um-confirm-user-avatar"></div>
                    <div>
                        <strong class="um-confirm-user-name"></strong>
                        <div class="drawer-header-sub um-confirm-user-sub"></div>
                    </div>
                </div>
            </div>
            <div class="um-modal-actions" style="padding:16px 22px 22px;">
                <button type="button" class="btn btn-secondary" data-close-modal="umSetStatusModal">Cancel</button>
                <button type="button" class="btn btn-primary" style="background:var(--red);" data-confirm-action="status">Confirm</button>
            </div>
        </div>
    </div>

    <!-- ===================== MODAL: Deactivate / Reactivate User (confirm) ===================== -->
    <div class="modal-backdrop" id="umDeactivateModal" aria-hidden="true">
        <div class="modal-box um-modal-box um-modal-box-sm" role="dialog" aria-modal="true">
            <div class="um-modal-header">
                <h3 id="umDeactivateTitle">Deactivate User</h3>
                <button type="button" class="um-modal-close" data-close-modal="umDeactivateModal" aria-label="Close">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
            </div>
            <div class="um-confirm-body">
                <div class="um-confirm-icon um-confirm-warn">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M18.36 6.64a9 9 0 1 1-12.73 0"></path>
                        <line x1="12" y1="2" x2="12" y2="12"></line>
                    </svg>
                </div>
                <p id="umDeactivateCopy">The account will be marked deactivated and hidden from active rosters. Continue for:</p>
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
                <button type="button" class="btn btn-primary" id="umDeactivateConfirmBtn" data-confirm-action="toggle-active">Deactivate User</button>
            </div>
        </div>
    </div>

    <!-- ===================== MODAL: Archive User (confirm) ===================== -->
    <div class="modal-backdrop" id="umArchiveModal" aria-hidden="true">
        <div class="modal-box um-modal-box um-modal-box-sm" role="dialog" aria-modal="true">
            <div class="um-modal-header">
                <h3>Archive User</h3>
                <button type="button" class="um-modal-close" data-close-modal="umArchiveModal" aria-label="Close">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
            </div>
            <div class="um-confirm-body">
                <div class="um-confirm-icon um-confirm-danger">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="3 6 5 6 21 6"></polyline>
                        <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"></path>
                        <path d="M10 11v6"></path>
                        <path d="M14 11v6"></path>
                    </svg>
                </div>
                <p>This hides the account from the active roster (their records are kept, and you can restore them later from the Archived view). Archive:</p>
                <div class="um-confirm-user-box">
                    <div class="um-avatar um-confirm-user-avatar"></div>
                    <div>
                        <strong class="um-confirm-user-name"></strong>
                        <div class="drawer-header-sub um-confirm-user-sub"></div>
                    </div>
                </div>
            </div>
            <div class="um-modal-actions" style="padding:16px 22px 22px;">
                <button type="button" class="btn btn-secondary" data-close-modal="umArchiveModal">Cancel</button>
                <button type="button" class="btn btn-primary" style="background:var(--red);" data-confirm-action="archive">Archive User</button>
            </div>
        </div>
    </div>

    <!-- ===================== MODAL: Restore User (confirm) ===================== -->
    <div class="modal-backdrop" id="umRestoreModal" aria-hidden="true">
        <div class="modal-box um-modal-box um-modal-box-sm" role="dialog" aria-modal="true">
            <div class="um-modal-header">
                <h3>Restore User</h3>
                <button type="button" class="um-modal-close" data-close-modal="umRestoreModal" aria-label="Close">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
            </div>
            <div class="um-confirm-body">
                <p>Restore this user back into the active roster:</p>
                <div class="um-confirm-user-box">
                    <div class="um-avatar um-confirm-user-avatar"></div>
                    <div>
                        <strong class="um-confirm-user-name"></strong>
                        <div class="drawer-header-sub um-confirm-user-sub"></div>
                    </div>
                </div>
            </div>
            <div class="um-modal-actions" style="padding:16px 22px 22px;">
                <button type="button" class="btn btn-secondary" data-close-modal="umRestoreModal">Cancel</button>
                <button type="button" class="btn btn-primary" data-confirm-action="restore">Restore User</button>
            </div>
        </div>
    </div>

    <script>
        // Same data the table above was rendered from, re-exposed as JSON so
        // the drawer/modals/export don't need a second round trip.
        const umUsers = <?php echo json_encode(array_combine(
                            array_column($users, 'id'),
                            $users
                        )); ?>;
        const umCurrentView = <?php echo json_encode($currentView); ?>;
    </script>
    <script src="../assets/js/user-management.js" defer></script>

    <?php if (count($users) === 0 && $currentView === 'active'): ?>
        <script>
            document.getElementById('umEmptyAddUserBtn')?.addEventListener('click', function() {
                document.getElementById('umAddUserBtn')?.click();
            });
        </script>
    <?php endif; ?>

</body>

</html>