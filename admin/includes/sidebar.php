<?php
// admin/includes/sidebar.php
// Reusable sidebar component for the Admin/Pharmacist portal — mirrors the
// structure of doctor/includes/sidebar.php and patient/includes/sidebar.php
// so the three portals read as one application.
//
// REAL BACKEND (2026-07-27): the notification bell was UI-only (static
// placeholder count + hardcoded sample dropdown data) until now. Wired up
// the same way doctor/includes/sidebar.php already does it —
// get_unread_notification_count() for the badge here, and
// includes/notifications_api.php (new, mirrors doctor's) for the
// dropdown's list/mark-read/count actions below. Admin name still comes
// from $_SESSION as before.
//
// COLLAPSIBLE (2026-09): on desktop widths the sidebar collapses to a slim
// icon rail with the panel button at its top (choice remembered per
// browser), and its menu scrollbar is hidden. The behaviour, CSS and JS are
// shared by every portal: see includes/sidebar_collapse.php.
//
// Usage: Set $current_page before including this file
// Example: $current_page = 'dashboard'; include 'includes/sidebar.php';

require_once __DIR__ . '/../../includes/notifications.php';
require_once __DIR__ . '/../../includes/sidebar_collapse.php';

// $conn is expected to already be available (every page including this
// sidebar also includes config/db.php first).
$unread_count = 0;
if (isset($conn) && isset($_SESSION['user_id'])) {
    $unread_count = get_unread_notification_count($conn, $_SESSION['user_id']);
}

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
            ['key' => 'hospital-census',        'label' => 'Hospital Census',         'href' => 'hospital-census.php',        'icon' => 'bed'],
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
            ['key' => 'system-settings',  'label' => 'System Settings',  'href' => 'system-settings.php',  'icon' => 'settings'],
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
    'users'        => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path>',
    'user'         => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle>',
    'calendar'     => '<rect x="3" y="4" width="18" height="18" rx="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line>',
    'cancel-circle' => '<circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line>',
    'settings'     => '<circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"></path>',
];
?>
<?php sidebar_collapse_head('portal'); ?>
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
    <?php sidebar_collapse_toggle(); ?>

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
                            <a href="<?php echo htmlspecialchars($item['href']); ?>" class="nav-link <?php echo $isActive; ?>" title="<?php echo htmlspecialchars($item['label']); ?>" aria-label="<?php echo htmlspecialchars($item['label']); ?>">
                                <svg class="nav-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <?php echo $icons[$item['icon']] ?? ''; ?>
                                </svg>
                                <span class="nav-label"><?php echo htmlspecialchars($item['label']); ?></span>
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
        <a href="profile.php" class="user-card" title="<?php echo htmlspecialchars($adminFullName); ?>" aria-label="<?php echo htmlspecialchars($adminFullName . ' - profile'); ?>">
            <div class="user-avatar"><?php echo htmlspecialchars($adminInitial); ?></div>
            <div class="user-info">
                <span class="user-name"><?php echo htmlspecialchars($adminFullName); ?></span>
                <span class="user-role"><?php echo htmlspecialchars($adminRoleLabel); ?></span>
            </div>
        </a>

        <a href="../logout.php" class="logout-btn" title="Log Out" aria-label="Log Out">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                <polyline points="16 17 21 12 16 7"></polyline>
                <line x1="21" y1="12" x2="9" y2="12"></line>
            </svg>
            <span class="logout-label">Log Out</span>
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

<?php sidebar_collapse_script(); ?>

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

    // Toggles the notification dropdown panel, loading its contents on open.
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
        fetch('includes/notifications_api.php?action=list')
            .then(res => res.json())
            .then(data => {
                const list = document.getElementById('notification-list');
                if (!data.notifications || data.notifications.length === 0) {
                    list.innerHTML = '<div class="notification-empty">No notifications yet.</div>';
                    return;
                }
                list.innerHTML = data.notifications.map((n, index) => `
                    <div class="notification-item notification-item-animated ${n.is_read ? '' : 'unread'}"
                         style="animation-delay: ${index * 70}ms"
                         onclick="openNotification(${n.notification_id}, '${n.link || ''}')">
                        <div class="notification-item-row">
                            ${notificationIcon(n.type)}
                            <div class="notification-item-body">
                                <div class="notification-message">${escapeHtml(n.message)}</div>
                                <div class="notification-time">${n.time_ago}</div>
                            </div>
                        </div>
                    </div>
                `).join('');
            })
            .catch(() => {
                document.getElementById('notification-list').innerHTML = '<div class="notification-empty">Could not load notifications.</div>';
            });
    }

    function openNotification(id, link) {
        fetch('includes/notifications_api.php?action=mark_read&id=' + id)
            .then(() => {
                if (link) window.location.href = link;
                else updateBellBadge();
            });
    }

    function markAllNotificationsRead() {
        fetch('includes/notifications_api.php?action=mark_all_read')
            .then(() => {
                loadNotifications();
                updateBellBadge();
            });
    }

    function updateBellBadge() {
        fetch('includes/notifications_api.php?action=count')
            .then(res => res.json())
            .then(data => {
                document.querySelectorAll('.bell-badge').forEach(b => b.remove());
                if (data.count > 0) {
                    document.querySelectorAll('.bell-btn').forEach(btn => {
                        const badge = document.createElement('span');
                        badge.className = 'bell-badge';
                        badge.textContent = data.count > 9 ? '9+' : data.count;
                        btn.appendChild(badge);
                    });
                }
            });
    }

    // Minimal per-type icon (no colored border/background — just the icon
    // itself in its type color) so a critical alert reads differently
    // from a routine info notification at a glance, per type stored on
    // notifications.type (see 006_add_notification_type.sql).
    function notificationIcon(type) {
        const icons = {
            info: '<circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line>',
            success: '<circle cx="12" cy="12" r="10"></circle><polyline points="8 12 11 15 16 9"></polyline>',
            warning: '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line>',
            critical: '<polygon points="7.86 2 16.14 2 22 7.86 22 16.14 16.14 22 7.86 22 2 16.14 2 7.86 7.86 2"></polygon><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line>',
        };
        const path = icons[type] || icons.info;
        return `<span class="notification-icon notification-icon-${type || 'info'}">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">${path}</svg>
        </span>`;
    }

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // Poll for new notifications every 30 seconds so the badge feels live
    // without needing websockets — same interval doctor/includes/sidebar.php uses.
    setInterval(updateBellBadge, 30000);
</script>