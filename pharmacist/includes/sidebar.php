<?php
// pharmacist/includes/sidebar.php
// Reusable sidebar component for the Pharmacist Portal — mirrors
// admin/includes/sidebar.php (which itself mirrors doctor/includes/sidebar.php
// and patient/includes/sidebar.php), so all portals read as one application.
//
// REAL BACKEND (2026-07-27): the notification bell was UI-only (static
// placeholder count + hardcoded sample dropdown data) until now — same
// gap admin/includes/sidebar.php had, fixed the same way:
// get_unread_notification_count() for the badge here, and
// includes/notifications_api.php (new, mirrors admin's/doctor's) for the
// dropdown's list/mark-read/count actions below.
//
// login.php now routes role = 'pharmacist' to pharmacist/dashboard.php.
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
// stock movement, not per-patient/per-prescription dispensing.
//
// PIVOT (2026-07-26): Storage Exit Scan ("Stock Release") removed — the
// barcode-scan stock-out step is no longer part of the portal.
//
// Dispense Stock is handled by inventory-counting staff. Pharmacists retain
// read-only visibility through the Dispense Log below.
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
            ['key' => 'record-stock-batch', 'label' => 'Record Stock Batch', 'href' => 'record-stock-batch.php', 'icon' => 'package'],
            ['key' => 'medicine-catalog', 'label' => 'Medicine Catalog',       'href' => 'medicine-catalog.php', 'icon' => 'pill'],
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
        'title' => 'Procurement',
        'items' => [
            ['key' => 'purchase-requests', 'label' => 'Purchase Requests', 'href' => 'purchase-requests.php', 'icon' => 'file-text'],
            ['key' => 'purchase-orders', 'label' => 'Purchase Orders', 'href' => 'purchase-orders.php', 'icon' => 'truck'],
        ],
    ],
    [
        'title' => 'Inventory Count',
        'items' => [
            ['key' => 'inventory-count', 'label' => 'Inventory Count', 'href' => 'inventory-count.php', 'icon' => 'check-square'],
            ['key' => 'inventory-count-history', 'label' => 'Assignment History', 'href' => 'inventory-count-history.php', 'icon' => 'history'],
        ],
    ],
    [
        'title' => 'Reports',
        'items' => [
            ['key' => 'reports', 'label' => 'Reports', 'href' => 'reports.php', 'icon' => 'file-text'],
            ['key' => 'audit-trail', 'label' => 'Audit Trail', 'href' => 'audit-trail.php', 'icon' => 'history'],
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
    'history'      => '<path d="M3 12a9 9 0 1 0 3-6.7"></path><polyline points="3 3 3 9 9 9"></polyline><polyline points="12 7 12 12 15 14"></polyline>',
    'package'      => '<path d="M12 2 3 7l9 5 9-5-9-5Z"></path><path d="M3 7v10l9 5 9-5V7"></path><path d="M12 12v10"></path>',
    'pill'         => '<path d="M10.5 20.5 20.5 10.5a4.95 4.95 0 0 0-7-7l-10 10a4.95 4.95 0 0 0 7 7Z"></path><path d="m8.5 8.5 7 7"></path>',
    'file-text'    => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line>',
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
    <?php sidebar_collapse_toggle(); ?>

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

    <!-- User card + standalone Log Out button, matching the Admin/Doctor
         Portal's static sidebar-bottom pattern (no dropdown). -->
    <div class="sidebar-bottom">
        <a href="profile.php" class="user-card" title="<?php echo htmlspecialchars($pharmacistFullName); ?>" aria-label="<?php echo htmlspecialchars($pharmacistFullName . ' - profile'); ?>">
            <div class="user-avatar"><?php echo htmlspecialchars($pharmacistInitial); ?></div>
            <div class="user-info">
                <span class="user-name"><?php echo htmlspecialchars($pharmacistFullName); ?></span>
                <span class="user-role"><?php echo htmlspecialchars($pharmacistRoleLabel); ?></span>
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
    // without needing websockets — same interval doctor/admin sidebars use.
    setInterval(updateBellBadge, 30000);
</script>