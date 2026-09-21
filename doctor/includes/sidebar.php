<?php
// doctor/includes/sidebar.php
// Reusable sidebar component with grouped navigation sections
// COLLAPSIBLE (2026-09): on desktop widths the sidebar collapses to a slim
// icon rail with the panel button at its top (choice remembered per
// browser), and its menu scrollbar is hidden. The behaviour, CSS and JS are
// shared by every portal: see includes/sidebar_collapse.php.
//
// Usage: Set $current_page before including this file
// Example: $current_page = 'dashboard'; include 'includes/sidebar.php';

require_once __DIR__ . '/../../includes/notifications.php';
require_once __DIR__ . '/../../includes/sidebar_collapse.php';

// Fetch unread notification count for the bell badge.
// $conn is expected to already be available (every page including this
// sidebar also includes config/db.php first).
$unread_count = 0;
if (isset($conn) && isset($_SESSION['user_id'])) {
    $unread_count = get_unread_notification_count($conn, $_SESSION['user_id']);
}

// Ensure session variables are available
$doctorFirstName = $_SESSION['first_name'] ?? '';
$doctorLastName = $_SESSION['last_name'] ?? '';
$doctorFullName = "Dr. " . $doctorFirstName . " " . $doctorLastName;
$doctorInitial = strtoupper(substr($doctorFirstName, 0, 1));
$doctorSpecialty = $_SESSION['specialty'] ?? 'Internal Medicine';

// Navigation structure with grouped sections
$navigation = [
    'main' => [
        'title' => 'Main',
        'items' => [
            ['key' => 'dashboard', 'label' => 'Dashboard', 'href' => 'dashboard.php', 'icon' => 'dashboard'],
            ['key' => 'my-schedule', 'label' => 'My Schedule', 'href' => 'my-schedule.php', 'icon' => 'calendar'],
        ],
    ],
    'patient_care' => [
        'title' => 'Patient Care',
        'items' => [
            ['key' => 'todays-queue', 'label' => "Appointment", 'href' => 'todays-queue.php', 'icon' => 'clock'],
            ['key' => 'confined-patients', 'label' => 'Confined Patients', 'href' => 'confined-patients.php', 'icon' => 'bed'],
            ['key' => 'patient-records', 'label' => 'Patient Records', 'href' => 'patient-records.php', 'icon' => 'file'],
            ['key' => 'appointment-history', 'label' => 'Appointment History', 'href' => 'appointment-history.php', 'icon' => 'history'],
        ],
    ],
    'follow_up' => [
        'title' => 'Follow-up',
        'items' => [
            ['key' => 'follow-up', 'label' => 'Follow-up', 'href' => 'follow-up.php', 'icon' => 'repeat'],
        ],
    ],
    'reports' => [
        'title' => 'Reports',
        'items' => [
            ['key' => 'reports', 'label' => 'My Reports', 'href' => 'reports.php', 'icon' => 'chart'],
        ],
    ],
    'account' => [
        'title' => 'Account',
        'items' => [
            ['key' => 'profile', 'label' => 'My Profile', 'href' => 'my-profile.php', 'icon' => 'user'],
        ],
    ],
];

// SVG icon templates
$icons = [
    'dashboard' => '<rect x="3" y="3" width="7" height="9"></rect><rect x="14" y="3" width="7" height="5"></rect><rect x="14" y="12" width="7" height="9"></rect><rect x="3" y="16" width="7" height="5"></rect>',
    'clock' => '<circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline>',
    'bed' => '<path d="M2 4v16"></path><path d="M2 8h18a2 2 0 0 1 2 2v10"></path><path d="M2 17h20"></path><path d="M6 8v9"></path>',
    'file' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line>',
    'message' => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>',
    'repeat' => '<polyline points="23 4 23 10 17 10"></polyline><polyline points="1 20 1 14 7 14"></polyline><path d="M3.51 9a9 9 0 0 1 14.85-3.36M20.49 15a9 9 0 0 1-14.85 3.36"></path>',
    'chart' => '<path d="M3 3v18h18"></path><path d="M18 17V9"></path><path d="M13 17V5"></path><path d="M8 17v-3"></path>',
    'user' => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle>',
    'user-plus' => '<path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="8.5" cy="7" r="4"></circle><line x1="20" y1="8" x2="20" y2="14"></line><line x1="23" y1="11" x2="17" y2="11"></line>',
    'calendar' => '<rect x="3" y="4" width="18" height="18" rx="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line>',
    'history' => '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline>',
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
        <span class="mobile-topbar-title">Doctor Portal</span>
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
            <span class="brand-name">Doctor Portal</span>
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
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endforeach; ?>
    </nav>

    <div class="sidebar-bottom">
        <div class="user-card" title="<?php echo htmlspecialchars($doctorFullName); ?>">
            <div class="user-avatar"><?php echo htmlspecialchars($doctorInitial); ?></div>
            <div class="user-info">
                <span class="user-name"><?php echo htmlspecialchars($doctorFullName); ?></span>
                <span class="user-role"><?php echo htmlspecialchars($doctorSpecialty); ?></span>
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

<!-- Notification floating popover (reused markup/behavior from patient/includes/sidebar.php) -->
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