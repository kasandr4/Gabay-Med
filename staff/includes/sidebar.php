<?php
// staff/includes/sidebar.php
// Reusable sidebar component for the Staff module, mirroring
// doctor/includes/sidebar.php's structure and CSS classes so it's visually
// consistent with the rest of GabayMed (staff pages already load
// doctor-dashboard.css for exactly this reason - no new styles needed here).
// Usage: Set $current_page before including this file
// Example: $current_page = 'check-in'; include 'includes/sidebar.php';

require_once __DIR__ . '/../../includes/notifications.php';

// Fetch unread notification count for the bell badge.
// $conn is expected to already be available (every page including this
// sidebar also includes config/db.php first).
$unread_count = 0;
if (isset($conn) && isset($_SESSION['user_id'])) {
    $unread_count = get_unread_notification_count($conn, $_SESSION['user_id']);
}

// Ensure session variables are available
$staffFirstName = $_SESSION['first_name'] ?? '';
$staffLastName  = $_SESSION['last_name'] ?? '';
$staffFullName  = trim($staffFirstName . " " . $staffLastName);
$staffInitial   = strtoupper(substr($staffFirstName, 0, 1));

// Navigation structure with grouped sections
$navigation = [
    'main' => [
        'title' => 'Main',
        'items' => [
            ['key' => 'check-in', 'label' => 'Patient Check-In', 'href' => 'check-in.php', 'icon' => 'clock'],
            ['key' => 'walk-in', 'label' => 'Walk-In Booking', 'href' => 'walk-in.php', 'icon' => 'user-plus'],
            ['key' => 'edit-priority', 'label' => 'Edit Patient Priority', 'href' => 'edit-priority.php', 'icon' => 'star'],
        ],
    ],
];

// SVG icon templates
$icons = [
    'clock' => '<circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline>',
    'user-plus' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><line x1="19" y1="8" x2="19" y2="14"></line><line x1="22" y1="11" x2="16" y2="11"></line>',
    'star' => '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon>',
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
        <span class="mobile-topbar-title">Staff Portal</span>
    </div>

    <button class="bell-btn" type="button" onclick="toggleNotifications()" aria-label="Notifications">
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
            <span class="brand-name">Staff Portal</span>
        </div>
        <button class="bell-btn bell-btn-desktop" type="button" onclick="toggleNotifications()" aria-label="Notifications">
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
                <div class="nav-section-title"><?php echo $section['title']; ?></div>
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

    <div class="sidebar-bottom">
        <div class="user-card">
            <div class="user-avatar"><?php echo htmlspecialchars($staffInitial); ?></div>
            <div class="user-info">
                <span class="user-name"><?php echo htmlspecialchars($staffFullName); ?></span>
                <span class="user-role">Front Desk Staff</span>
            </div>
        </div>
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

<!-- Notification floating popover (reused markup/behavior from doctor/includes/sidebar.php) -->
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
                        <div class="notification-message">${escapeHtml(n.message)}</div>
                        <div class="notification-time">${n.time_ago}</div>
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

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // Poll for new notifications every 30 seconds so the badge feels live
    // without needing websockets.
    setInterval(updateBellBadge, 30000);
</script>