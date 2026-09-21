<?php
// capitol/includes/sidebar.php
// Sidebar for the new Capitol portal (2026-09-20) - mirrors pharmacist/
// includes/sidebar.php's structure exactly (same icon set, same mobile
// topbar/notification-panel markup/behavior) so this reads as the same
// application, just a much smaller nav for a much smaller role: Capitol
// only ever touches Purchase Requests (their inbound queue) and
// Purchase Orders (what they create/send back). Everything else in this
// app - inventory, dispensing, patients, appointments - is none of
// Capitol's business and isn't offered here.

require_once __DIR__ . '/../../includes/notifications.php';

$unread_count = 0;
if (isset($conn) && isset($_SESSION['user_id'])) {
    $unread_count = get_unread_notification_count($conn, $_SESSION['user_id']);
}

$capitolFirstName = $_SESSION['first_name'] ?? 'Capitol';
$capitolLastName  = $_SESSION['last_name'] ?? 'User';
$capitolFullName  = trim($capitolFirstName . ' ' . $capitolLastName);
$capitolInitial   = strtoupper(substr($capitolFirstName, 0, 1));

$navigation = [
    [
        'title' => 'Procurement',
        'items' => [
            ['key' => 'purchase-requests', 'label' => 'Purchase Requests', 'href' => 'purchase-requests.php', 'icon' => 'file-text'],
            ['key' => 'purchase-orders', 'label' => 'Purchase Orders', 'href' => 'purchase-orders.php', 'icon' => 'truck'],
        ],
    ],
];

$icons = [
    'truck'     => '<path d="M10 17h4V5H2v12h3"></path><path d="M20 17h2v-3.34a4 4 0 0 0-1.17-2.83L19 9h-5v8h1"></path><circle cx="7.5" cy="17.5" r="2.5"></circle><circle cx="17.5" cy="17.5" r="2.5"></circle>',
    'file-text' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line>',
];
?>
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
        <span class="mobile-topbar-title">Capitol Portal</span>
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

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<aside class="sidebar" id="sidebar">
    <div class="sidebar-top">
        <div class="brand">
            <img src="../logo-icon.png" alt="GabayMed logo" class="brand-logo">
            <span class="brand-name">Capitol Portal</span>
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

    <div class="sidebar-bottom">
        <div class="user-card">
            <div class="user-avatar"><?php echo htmlspecialchars($capitolInitial); ?></div>
            <div class="user-info">
                <span class="user-name"><?php echo htmlspecialchars($capitolFullName); ?></span>
                <span class="user-role">Provincial Capitol</span>
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
    (function() {
        const toggleBtn = document.getElementById('sidebarToggle');
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        if (!toggleBtn || !sidebar || !overlay) return;

        function openSidebar() {
            sidebar.classList.add('active');
            overlay.classList.add('active');
            document.body.classList.add('sidebar-open');
            toggleBtn.setAttribute('aria-expanded', 'true');
        }

        function closeSidebar() {
            sidebar.classList.remove('active');
            overlay.classList.remove('active');
            document.body.classList.remove('sidebar-open');
            toggleBtn.setAttribute('aria-expanded', 'false');
        }

        toggleBtn.addEventListener('click', function() {
            sidebar.classList.contains('active') ? closeSidebar() : openSidebar();
        });
        overlay.addEventListener('click', closeSidebar);
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') closeSidebar();
        });
    })();

    // Notification dropdown - identical logic to pharmacist/includes/
    // sidebar.php's own copy, pointed at this portal's own
    // includes/notifications_api.php (role='capitol'), which is why this
    // is inlined here rather than pulled from a shared assets/js file -
    // pharmacist's copy is inlined the same way, for the same reason
    // (every portal's sidebar fetches its OWN notifications_api.php via
    // a relative path, so the JS itself has no portal-specific logic to
    // centralize beyond that path already being relative).
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

    setInterval(updateBellBadge, 30000);
</script>
