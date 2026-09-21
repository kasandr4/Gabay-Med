<?php
// staff/includes/sidebar.php
// Reusable sidebar component for the Staff module, mirroring
// doctor/includes/sidebar.php's structure and CSS classes so it's visually
// consistent with the rest of GabayMed (staff pages already load
// doctor-dashboard.css for exactly this reason - no new styles needed here).
// COLLAPSIBLE (2026-09): on desktop widths the sidebar collapses to a slim
// icon rail with the panel button at its top (choice remembered per
// browser), and its menu scrollbar is hidden. The behaviour, CSS and JS are
// shared by every portal: see includes/sidebar_collapse.php.
//
// Usage: Set $current_page before including this file
// Example: $current_page = 'check-in'; include 'includes/sidebar.php';

require_once __DIR__ . '/../../includes/notifications.php';
require_once __DIR__ . '/../../includes/sidebar_collapse.php';
require_once __DIR__ . '/../../includes/schedule_conflicts.php';

// Fetch unread notification count for the bell badge.
// $conn is expected to already be available (every page including this
// sidebar also includes config/db.php first).
$unread_count = 0;
$conflict_count = 0;
if (isset($conn) && isset($_SESSION['user_id'])) {
    $unread_count = get_unread_notification_count($conn, $_SESSION['user_id']);
    $conflict_count = count_schedule_conflicts($conn);
}

// Ensure session variables are available
$staffFirstName = $_SESSION['first_name'] ?? '';
$staffLastName  = $_SESSION['last_name'] ?? '';
$staffFullName  = trim($staffFirstName . " " . $staffLastName);
$staffInitial   = strtoupper(substr($staffFirstName, 0, 1));

// Two unrelated jobs share the 'staff' role — see 013_staff_subtype.sql
// and includes/auth_guard.php's require_staff_type(). Each account only
// ever sees the nav section for their own job; the other job's pages are
// also blocked server-side by require_staff_type() on those pages
// themselves, so this isn't just a cosmetic hide — an account really
// can't reach the other section's pages even by typing the URL.
$staffType = $_SESSION['staff_type'] ?? 'front_desk';

$navigation = [];

if ($staffType === 'inventory') {
    $navigation['inventory'] = [
        'title' => 'Inventory',
        'items' => [
            ['key' => 'staff-dashboard', 'label' => 'Dashboard', 'href' => 'dashboard.php', 'icon' => 'dashboard'],
            ['key' => 'inventory-count', 'label' => 'Inventory Count', 'href' => 'inventory-count.php', 'icon' => 'check-square'],
            ['key' => 'submitted-inventory', 'label' => 'Submitted Inventory', 'href' => 'submitted-inventory.php', 'icon' => 'file-text'],
            ['key' => 'prescription-queue', 'label' => 'Prescription Queue', 'href' => 'prescription-queue.php', 'icon' => 'file-text'],
            ['key' => 'restock-management', 'label' => 'Restock Management', 'href' => 'restock-management.php', 'icon' => 'file-text'],
        ],
    ];
} elseif ($staffType === 'laboratory') {
    $navigation['laboratory'] = [
        'title' => 'Laboratory',
        'items' => [
            ['key' => 'lab-queue', 'label' => 'Lab Queue', 'href' => 'lab-queue.php', 'icon' => 'file-text'],
        ],
    ];
} else {
    $navigation['main'] = [
        'title' => 'Main',
        'items' => [
            ['key' => 'check-in', 'label' => 'Patient Check-In', 'href' => 'check-in.php', 'icon' => 'clock'],
            ['key' => 'walk-in', 'label' => 'Walk-In Booking', 'href' => 'walk-in.php', 'icon' => 'user-plus'],
            ['key' => 'schedule-conflicts', 'label' => 'Schedule Conflicts', 'href' => 'schedule-conflicts.php', 'icon' => 'alert-triangle', 'badge' => $conflict_count],
        ],
    ];
}
$navigation['common'] = [
    'title' => 'Account',
    'items' => [
        ['key' => 'my-info', 'label' => 'My Info', 'href' => 'my-info.php', 'icon' => 'user'],
    ],
];
// SVG icon templates
$icons = [
    'dashboard' => '<rect x="3" y="3" width="7" height="9"></rect><rect x="14" y="3" width="7" height="5"></rect><rect x="14" y="12" width="7" height="9"></rect><rect x="3" y="16" width="7" height="5"></rect>',
    'clock' => '<circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline>',
    'alert-triangle' => '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line>',
    'user-plus' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><line x1="19" y1="8" x2="19" y2="14"></line><line x1="22" y1="11" x2="16" y2="11"></line>',
    'star' => '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon>',
    'check-square' => '<polyline points="9 11 12 14 22 4"></polyline><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path>',
    'scan' => '<path d="M3 7V5a2 2 0 0 1 2-2h2"></path><path d="M17 3h2a2 2 0 0 1 2 2v2"></path><path d="M21 17v2a2 2 0 0 1-2 2h-2"></path><path d="M7 21H5a2 2 0 0 1-2-2v-2"></path><line x1="7" y1="12" x2="17" y2="12"></line>',
    'user' => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle>',
    'file-text' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line>',
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
    <?php sidebar_collapse_toggle(); ?>

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
                            <a href="<?php echo htmlspecialchars($item['href']); ?>" class="nav-link <?php echo $isActive; ?>" title="<?php echo htmlspecialchars($item['label']); ?>" aria-label="<?php echo htmlspecialchars($item['label']); ?>">
                                <svg class="nav-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <?php echo $icons[$item['icon']] ?? ''; ?>
                                </svg>
                                <span class="nav-label"><?php echo htmlspecialchars($item['label']); ?></span>
                                <?php if (!empty($item['badge'])): ?>
                                    <span class="nav-badge"><?php echo $item['badge'] > 9 ? '9+' : (int) $item['badge']; ?></span>
                                <?php endif; ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endforeach; ?>
    </nav>

    <div class="sidebar-bottom">
        <div class="user-card" title="<?php echo htmlspecialchars($staffFullName); ?>">
            <div class="user-avatar"><?php echo htmlspecialchars($staffInitial); ?></div>
            <div class="user-info">
                <span class="user-name"><?php echo htmlspecialchars($staffFullName); ?></span>
                <span class="user-role"><?php echo $staffType === 'inventory' ? 'Inventory Counting Staff' : ($staffType === 'laboratory' ? 'Laboratory Staff' : 'Front Desk Staff'); ?></span>
            </div>
        </div>
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

<?php sidebar_collapse_script(); ?>

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