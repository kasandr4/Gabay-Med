<?php
// pharmacist/includes/sidebar.php
// Reusable sidebar component for the Pharmacist Portal — mirrors
// admin/includes/sidebar.php (which itself mirrors doctor/includes/sidebar.php
// and patient/includes/sidebar.php), so all portals read as one application.
//
// UI-ONLY NOTE: This module is being built ahead of the backend. The unread
// notification count and pharmacist name below are placeholder/static values
// where a real query would normally run (see the "TODO(backend)" comments).
// When the data layer is wired up, replace those spots the same way
// doctor/includes/sidebar.php pulls $unread_count from get_unread_notification_count().
//
// login.php now routes role = 'pharmacist' to pharmacist/dashboard.php.
//
// Usage: Set $current_page before including this file
// Example: $current_page = 'dashboard'; include 'includes/sidebar.php';

// TODO(backend): replace with get_unread_notification_count($conn, $_SESSION['user_id'])
$unread_count = 2;

// TODO(backend): replace with $_SESSION values once auth session shape is finalized
$pharmacistFirstName = $_SESSION['first_name'] ?? 'Pharmacist';
$pharmacistLastName  = $_SESSION['last_name'] ?? 'User';
$pharmacistFullName  = trim($pharmacistFirstName . ' ' . $pharmacistLastName);
$pharmacistInitial   = strtoupper(substr($pharmacistFirstName, 0, 1));
$pharmacistRoleLabel = $_SESSION['role_label'] ?? 'Pharmacist';

// Navigation grouped into titled sections, mirroring the Admin Portal
// sidebar's Main / Operations / Reports / Management layout — grouped here
// around the pharmacist's actual daily workflow instead.
//
// PIVOT (2026-07-17): Dispense Medicine removed — the portal tracks box-level
// stock movement, not per-patient/per-prescription dispensing. Storage Exit
// Scan and Delivery Receiving are grouped together under "Stock Movement"
// since they're now the two directions the same box count moves (in via
// delivery, out via scan).
$navigation = [
    [
        'title' => 'Main',
        'items' => [
            ['key' => 'dashboard', 'label' => 'Dashboard', 'href' => 'dashboard.php', 'icon' => 'dashboard'],
        ],
    ],
    [
        'title' => 'Stock Movement',
        'items' => [
            ['key' => 'exit-scan', 'label' => 'Stock Release',  'href' => 'storage-exit-scan.php',  'icon' => 'scan'],
            ['key' => 'delivery',  'label' => 'Delivery Receiving', 'href' => 'delivery-receiving.php', 'icon' => 'truck'],
        ],
    ],
    [
        'title' => 'Inventory',
        'items' => [
            ['key' => 'inventory', 'label' => 'Inventory View', 'href' => 'inventory.php', 'icon' => 'box'],
            ['key' => 'expiry-tracking', 'label' => 'Expiry Tracking', 'href' => 'expiry-tracking.php', 'icon' => 'calendar'],
            ['key' => 'reorder-insights', 'label' => 'Reorder Insights', 'href' => 'reorder-insights.php', 'icon' => 'trending-up'],
        ],
    ],
    [
        'title' => 'Reconciliation',
        'items' => [
            ['key' => 'reconciliation', 'label' => 'Reconciliation Entry', 'href' => 'reconciliation.php', 'icon' => 'check-square'],
        ],
    ],
    [
        'title' => 'Reports',
        'items' => [
            // UI ONLY (2026-07-18): reports.php is a static/mock preview
            // module — see the note at the top of that file.
            ['key' => 'reports', 'label' => 'Reports', 'href' => 'reports.php', 'icon' => 'file-text'],
        ],
    ],
];

// SVG icon templates (same stroke-based icon style used across every
// portal's sidebar, so icons look consistent even though 'pill', 'scan'
// and 'truck' are new to this portal).
$icons = [
    'dashboard'    => '<rect x="3" y="3" width="7" height="9"></rect><rect x="14" y="3" width="7" height="5"></rect><rect x="14" y="12" width="7" height="9"></rect><rect x="3" y="16" width="7" height="5"></rect>',
    'scan'         => '<path d="M3 7V5a2 2 0 0 1 2-2h2"></path><path d="M17 3h2a2 2 0 0 1 2 2v2"></path><path d="M21 17v2a2 2 0 0 1-2 2h-2"></path><path d="M7 21H5a2 2 0 0 1-2-2v-2"></path><line x1="7" y1="12" x2="17" y2="12"></line>',
    'box'          => '<path d="M21 8v13H3V8"></path><path d="M1 3h22v5H1z"></path><path d="M10 12h4"></path>',
    'truck'        => '<path d="M10 17h4V5H2v12h3"></path><path d="M20 17h2v-3.34a4 4 0 0 0-1.17-2.83L19 9h-5v8h1"></path><circle cx="7.5" cy="17.5" r="2.5"></circle><circle cx="17.5" cy="17.5" r="2.5"></circle>',
    'check-square' => '<polyline points="9 11 12 14 22 4"></polyline><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path>',
    'calendar'     => '<rect x="3" y="4" width="18" height="18" rx="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line>',
    'trending-up'  => '<polyline points="23 6 13.5 15.5 8.5 10.5 1 18"></polyline><polyline points="17 6 23 6 23 12"></polyline>',
    'file-text'    => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line>',
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
        <span class="mobile-topbar-title">Pharmacist Portal</span>
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
            <span class="brand-name">Pharmacist Portal</span>
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

    <!-- User card + standalone Log Out button, matching the Admin/Doctor
         Portal's static sidebar-bottom pattern (no dropdown). -->
    <div class="sidebar-bottom">
        <a href="profile.php" class="user-card">
            <div class="user-avatar"><?php echo htmlspecialchars($pharmacistInitial); ?></div>
            <div class="user-info">
                <span class="user-name"><?php echo htmlspecialchars($pharmacistFullName); ?></span>
                <span class="user-role"><?php echo htmlspecialchars($pharmacistRoleLabel); ?></span>
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
    // by doctor/includes/sidebar.php once the pharmacist API endpoint exists.
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
        // TODO(backend): replace with fetch('includes/notifications_api.php')
        const sample = [{
                text: 'Amoxicillin 500mg is below the low-stock threshold',
                time: '18 minutes ago'
            },
            {
                text: 'Delivery from MedSupply Corp marked as issued',
                time: '2 hours ago'
            },
        ];
        if (sample.length === 0) {
            list.innerHTML = '<div class="notification-empty">No notifications</div>';
            return;
        }
        list.innerHTML = sample.map(n => `
            <div class="notification-item">
                <p>${n.text}</p>
                <span class="notification-time">${n.time}</span>
            </div>
        `).join('');
    }

    function markAllNotificationsRead() {
        // TODO(backend): POST to notifications_api.php to mark all read
        document.querySelectorAll('.bell-badge').forEach(el => el.remove());
    }
</script>