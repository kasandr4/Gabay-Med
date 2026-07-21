<?php
// admin/includes/sidebar.php
// Reusable sidebar component for the Admin/Pharmacist portal — mirrors the
// structure of doctor/includes/sidebar.php and patient/includes/sidebar.php
// so the three portals read as one application.
//
// UI-ONLY NOTE: This module is being built ahead of the backend. The unread
// notification count and admin name below are placeholder/static values
// where a real query would normally run (see the "TODO(backend)" comments).
// When the data layer is wired up, replace those spots the same way
// doctor/includes/sidebar.php pulls $unread_count from get_unread_notification_count().
//
// Usage: Set $current_page before including this file
// Example: $current_page = 'dashboard'; include 'includes/sidebar.php';

// TODO(backend): replace with get_unread_notification_count($conn, $_SESSION['user_id'])
$unread_count = 3;

// TODO(backend): replace with $_SESSION values once auth session shape is finalized
$adminFirstName = $_SESSION['first_name'] ?? 'Admin';
$adminLastName  = $_SESSION['last_name'] ?? 'User';
$adminFullName  = trim($adminFirstName . ' ' . $adminLastName);
$adminInitial   = strtoupper(substr($adminFirstName, 0, 1));
$adminRoleLabel = $_SESSION['role_label'] ?? 'Administrator';

// Navigation grouped into titled sections, mirroring the Doctor Portal
// sidebar's MAIN / PATIENT CARE / FOLLOW-UP / REPORTS / ACCOUNT layout.
$navigation = [
    [
        'title' => 'Main',
        'items' => [
            ['key' => 'dashboard', 'label' => 'Dashboard', 'href' => 'dashboard.php', 'icon' => 'dashboard'],
        ],
    ],
    [
        'title' => 'Operations',
        'items' => [
            ['key' => 'hospital-census', 'label' => 'Hospital Census',       'href' => 'hospital-census.php',       'icon' => 'bed'],
            ['key' => 'inventory',       'label' => 'Inventory Procurement', 'href' => 'inventory-procurement.php', 'icon' => 'box'],
            ['key' => 'delivery',        'label' => 'Delivery Receiving',    'href' => 'delivery-receiving.php',    'icon' => 'truck'],
            ['key' => 'reconciliation',  'label' => 'Reconciliation',        'href' => 'reconciliation.php',        'icon' => 'check-square'],
        ],
    ],
    [
        'title' => 'Reports',
        'items' => [
            ['key' => 'reports', 'label' => 'Hospital Reports', 'href' => 'hospital-reports.php', 'icon' => 'chart'],
        ],
    ],
    [
        'title' => 'Management',
        'items' => [
            ['key' => 'user-management',  'label' => 'User Management',  'href' => 'user-management.php',  'icon' => 'users'],
            ['key' => 'doctor-schedule',  'label' => 'Doctor Schedules', 'href' => 'doctor-schedule.php',  'icon' => 'calendar'],
        ],
    ],
];

// SVG icon templates (same stroke-based icon style used across every
// portal's sidebar, so icons look consistent even though this set is new).
$icons = [
    'dashboard'    => '<rect x="3" y="3" width="7" height="9"></rect><rect x="14" y="3" width="7" height="5"></rect><rect x="14" y="12" width="7" height="9"></rect><rect x="3" y="16" width="7" height="5"></rect>',
    'bed'          => '<path d="M2 4v16"></path><path d="M2 8h18a2 2 0 0 1 2 2v10"></path><path d="M2 17h20"></path><path d="M6 8v9"></path>',
    'box'          => '<path d="M21 8v13H3V8"></path><path d="M1 3h22v5H1z"></path><path d="M10 12h4"></path>',
    'truck'        => '<path d="M10 17h4V5H2v12h3"></path><path d="M20 17h2v-3.34a4 4 0 0 0-1.17-2.83L19 9h-5v8h1"></path><circle cx="7.5" cy="17.5" r="2.5"></circle><circle cx="17.5" cy="17.5" r="2.5"></circle>',
    'chart'        => '<path d="M3 3v18h18"></path><path d="M18 17V9"></path><path d="M13 17V5"></path><path d="M8 17v-3"></path>',
    'check-square' => '<polyline points="9 11 12 14 22 4"></polyline><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path>',
    'users'        => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path>',
    'user'         => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle>',
    'calendar'     => '<rect x="3" y="4" width="18" height="18" rx="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line>',
];
?>
<!-- Mobile top navigation bar (hidden on desktop; shows hamburger + brand + notifications) -->
<header class="mobile-topbar" id="mobileTopbar">
    <button class="sidebar-toggle" id="sidebarToggle" type="button" aria-label="Open menu" aria-expanded="false" aria-controls="sidebar">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <line x1="3" y1="6" x2="21" y2="6"></line>
            <line x1="3" y1="12" x2="21" y2="12"></line>
            <line x1="3" y1="18" x2="21" y2="18"></line>
        </svg>
    </button>

    <div class="mobile-topbar-brand">
        <img src="../logo-icon.png" alt="GabayMed logo" class="mobile-topbar-logo">
        <span class="mobile-topbar-title">Admin Portal</span>
    </div>

    <button class="bell-btn" onclick="toggleNotifications()" aria-label="Notifications">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"></path>
            <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
        </svg>
        <?php if ($unread_count > 0): ?>
            <span class="bell-badge"><?= $unread_count > 9 ? '9+' : $unread_count ?></span>
        <?php endif; ?>
    </button>
</header>

<!-- Overlay shown behind the sidebar on mobile -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- Sidebar -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-top">
        <div class="brand">
            <img src="../logo-icon.png" alt="GabayMed logo" class="brand-logo">
            <span class="brand-name">Admin Portal</span>
        </div>
        <button class="bell-btn bell-btn-desktop" onclick="toggleNotifications()" aria-label="Notifications">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"></path>
                <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
            </svg>
            <?php if ($unread_count > 0): ?>
                <span class="bell-badge"><?= $unread_count > 9 ? '9+' : $unread_count ?></span>
            <?php endif; ?>
        </button>
    </div>

    <nav class="sidebar-nav">
        <?php foreach ($navigation as $section) : ?>
            <div class="nav-section-group">
                <span class="nav-section-title"><?php echo htmlspecialchars(strtoupper($section['title'])); ?></span>
                <ul class="nav-section-list">
                    <?php foreach ($section['items'] as $item) : ?>
                        <?php $isActive = (isset($current_page) && $current_page === $item['key']) ? 'active' : ''; ?>
                        <li>
                            <a href="<?php echo htmlspecialchars($item['href']); ?>" class="nav-link <?php echo $isActive; ?>">
                                <svg class="nav-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <?php echo $icons[$item['icon']] ?? ''; ?>
                                </svg>
                                <?php echo htmlspecialchars($item['label']); ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endforeach; ?>
    </nav>

    <!-- User card + standalone Log Out button, matching the Doctor Portal's
         static sidebar-bottom pattern (no dropdown — the card links straight
         to the profile page, and Log Out sits below it as its own button). -->
    <div class="sidebar-bottom">
        <a href="profile.php" class="user-card">
            <div class="user-avatar"><?php echo htmlspecialchars($adminInitial); ?></div>
            <div class="user-info">
                <span class="user-name"><?php echo htmlspecialchars($adminFullName); ?></span>
                <span class="user-role"><?php echo htmlspecialchars($adminRoleLabel); ?></span>
            </div>
        </a>

        <a href="../logout.php" class="logout-btn">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                <polyline points="16 17 21 12 16 7"></polyline>
                <line x1="21" y1="12" x2="9" y2="12"></line>
            </svg>
            Log Out
        </a>
    </div>
</aside>

<!-- Notification floating popover (same markup/behavior as the other portals) -->
<div class="notification-panel" id="notification-panel">
    <div class="notification-panel-header">
        <span>Notifications</span>
        <button class="mark-all-read-btn" onclick="markAllNotificationsRead()">Mark all as read</button>
    </div>
    <div class="notification-list" id="notification-list">
        <div class="notification-loading">Loading...</div>
    </div>
</div>
<div class="notification-overlay" id="notification-overlay" onclick="toggleNotifications()"></div>

<script>
    // Mobile sidebar open/close. Matches doctor-dashboard.css's actual
    // convention exactly: .sidebar.active / .sidebar-overlay.active for the
    // transform + fade, plus body.sidebar-open so .main-content becomes
    // non-interactive while the off-canvas menu is open.
    (function() {
        const toggleBtn = document.getElementById('sidebarToggle');
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        if (!toggleBtn || !sidebar || !overlay) return;

        function closeSidebar() {
            sidebar.classList.remove('active');
            overlay.classList.remove('active');
            document.body.classList.remove('sidebar-open');
            toggleBtn.setAttribute('aria-expanded', 'false');
        }

        function openSidebar() {
            sidebar.classList.add('active');
            overlay.classList.add('active');
            document.body.classList.add('sidebar-open');
            toggleBtn.setAttribute('aria-expanded', 'true');
        }
        toggleBtn.addEventListener('click', () => {
            sidebar.classList.contains('active') ? closeSidebar() : openSidebar();
        });
        overlay.addEventListener('click', closeSidebar);
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') closeSidebar();
        });
    })();

    // Toggles the notification dropdown panel.
    // TODO(backend): loadNotifications() currently renders static sample
    // data (see below) instead of fetching includes/notifications_api.php,
    // since this module is UI-only for now. Swap in the fetch() call used
    // by doctor/includes/sidebar.php once the admin API endpoint exists.
    function toggleNotifications() {
        const panel = document.getElementById('notification-panel');
        const overlay = document.getElementById('notification-overlay');
        const isOpening = !panel.classList.contains('panel-open');

        panel.classList.toggle('panel-open');
        overlay.classList.toggle('overlay-visible');

        if (isOpening) {
            loadNotifications();
        }
    }

    function loadNotifications() {
        const list = document.getElementById('notification-list');
        const sample = [{
                message: 'New purchase request submitted for Amoxicillin 500mg.',
                time_ago: '12 minutes ago',
                is_read: false
            },
            {
                message: 'Dr. Santos account created successfully.',
                time_ago: '1 hour ago',
                is_read: false
            },
            {
                message: 'Reconciliation for Pharmacy Dept. is pending sign-off.',
                time_ago: '3 hours ago',
                is_read: true
            },
        ];
        list.innerHTML = sample.map((n, index) => `
            <div class="notification-item notification-item-animated ${n.is_read ? '' : 'unread'}"
                 style="animation-delay: ${index * 70}ms">
                <div class="notification-message">${escapeHtml(n.message)}</div>
                <div class="notification-time">${n.time_ago}</div>
            </div>
        `).join('');
    }

    function markAllNotificationsRead() {
        document.querySelectorAll('.notification-item.unread').forEach(item => item.classList.remove('unread'));
    }

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
</script>