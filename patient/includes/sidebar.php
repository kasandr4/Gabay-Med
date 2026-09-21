<?php
// patient/includes/sidebar.php
//
// Shared sidebar navigation for ALL patient-facing pages.
// Include this AFTER auth_guard.php has already run require_role('patient').
//
// COLLAPSIBLE (2026-09): on desktop widths the sidebar collapses to a slim
// icon rail with the panel button at its top (choice remembered per
// browser), and its menu scrollbar is hidden. The behaviour, CSS and JS are
// shared by every portal: see includes/sidebar_collapse.php.
//
// USAGE: set $active_page before including this file, so the matching
// nav item gets highlighted. Example:
//   $active_page = 'dashboard';
//   include 'includes/sidebar.php';

require_once __DIR__ . '/../../includes/notifications.php';
require_once __DIR__ . '/../../includes/sidebar_collapse.php';

if (!isset($active_page)) {
    $active_page = '';
}

// Fetch unread notification count for the bell badge.
// $conn is expected to already be available (every page including this
// sidebar also includes config/db.php first).
$unread_count = 0;
if (isset($conn) && isset($_SESSION['user_id'])) {
    $unread_count = get_unread_notification_count($conn, $_SESSION['user_id']);
}

// Confined patients shouldn't land on the normal Dashboard or start a new
// booking (dashboard.php and book-appointment.php both already enforce this
// server-side as a safety net). Here we just need to know the status so the
// sidebar can show a warning panel instead of letting those links navigate
// away — the actual page-level redirects stay in place for direct URL access.
$patient_confined = false;
if (isset($conn) && isset($_SESSION['user_id'])) {
    $stmt = $conn->prepare("SELECT status FROM users WHERE user_id = ?");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $status_row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $patient_confined = ($status_row && $status_row['status'] === 'confined');
}
$restricted_while_confined = ['dashboard', 'book-appointment'];

// Each nav item: [label, link, page_key, icon]
// page_key is compared against $active_page to apply the "active" highlight.
// The icon (a key into $nav_icons below) is what stays visible when the
// sidebar is collapsed to its icon rail.
$nav_items = [
    ['Dashboard',           'dashboard.php',         'dashboard',           'dashboard'],
    ['Book Appointment',    'book-appointment.php',  'book-appointment',    'calendar-plus'],
    ['Appointment History', 'appointment-history.php', 'appointment-history', 'history'],
    ['Prescriptions',       'prescriptions.php',     'prescriptions',       'pill'],
    ['Laboratory',          'lab-orders.php',        'lab-orders',          'flask'],
    ['My Confinement',      'confinement-dashboard.php', 'confinement',     'bed'],
    ['My Profile',          'profile.php',           'profile',             'user'],
];

// Stroke icons in the same style the staff portals' sidebars use.
$nav_icons = [
    'dashboard'     => '<rect x="3" y="3" width="7" height="9"></rect><rect x="14" y="3" width="7" height="5"></rect><rect x="14" y="12" width="7" height="9"></rect><rect x="3" y="16" width="7" height="5"></rect>',
    'calendar-plus' => '<rect x="3" y="4" width="18" height="18" rx="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line><line x1="12" y1="14" x2="12" y2="18"></line><line x1="10" y1="16" x2="14" y2="16"></line>',
    'history'       => '<path d="M3 12a9 9 0 1 0 3-6.7"></path><polyline points="3 3 3 9 9 9"></polyline><polyline points="12 7 12 12 15 14"></polyline>',
    'pill'          => '<path d="M10.5 20.5 20.5 10.5a4.95 4.95 0 0 0-7-7l-10 10a4.95 4.95 0 0 0 7 7Z"></path><path d="m8.5 8.5 7 7"></path>',
    'flask'         => '<path d="M9 3h6"></path><path d="M10 3v6L4.5 19a1.5 1.5 0 0 0 1.3 2.2h12.4a1.5 1.5 0 0 0 1.3-2.2L14 9V3"></path><line x1="7.5" y1="14" x2="16.5" y2="14"></line>',
    'bed'           => '<path d="M3 20v-8a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v8"></path><path d="M3 16h18"></path><path d="M6 10V7a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v3"></path>',
    'user'          => '<circle cx="12" cy="8" r="4"></circle><path d="M4 21v-1a6 6 0 0 1 6-6h4a6 6 0 0 1 6 6v1"></path>',
];
?>
<?php sidebar_collapse_head('patient'); ?>
<!-- Mobile-only top bar with hamburger toggle -->
<header class="mobile-topbar">
    <div class="mobile-topbar-left">
        <button class="hamburger-btn" onclick="toggleSidebar()" aria-label="Open menu">
            <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M3 6h18M3 12h18M3 18h18" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
            </svg>
        </button>
        <div class="mobile-topbar-logo">
            <img src="../logo-icon.png" alt="GabayMed logo">
            <span>GabayMed</span>
        </div>
    </div>
    <button class="bell-btn" onclick="toggleNotifications()" aria-label="Notifications">
        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M18 8a6 6 0 10-12 0c0 7-3 9-3 9h18s-3-2-3-9" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
            <path d="M13.73 21a2 2 0 01-3.46 0" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
        <?php if ($unread_count > 0): ?>
            <span class="bell-badge"><?= $unread_count > 9 ? '9+' : $unread_count ?></span>
        <?php endif; ?>
    </button>
</header>

<!-- Dark overlay shown behind the sidebar when it's open on mobile -->
<div class="sidebar-overlay" id="sidebar-overlay" onclick="toggleSidebar()"></div>

<aside class="sidebar sidebar-dark" id="sidebar">
    <?php sidebar_collapse_toggle(); ?>

    <div class="sidebar-logo">
        <img src="../logo-icon.png" alt="GabayMed logo">
        <span class="brand-text">GabayMed</span>
        <button class="bell-btn bell-btn-desktop" onclick="toggleNotifications()" aria-label="Notifications">
            <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M18 8a6 6 0 10-12 0c0 7-3 9-3 9h18s-3-2-3-9" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                <path d="M13.73 21a2 2 0 01-3.46 0" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
            <?php if ($unread_count > 0): ?>
                <span class="bell-badge"><?= $unread_count > 9 ? '9+' : $unread_count ?></span>
            <?php endif; ?>
        </button>
    </div>

    <nav class="sidebar-nav">
        <?php foreach ($nav_items as [$label, $link, $key, $icon]):
            $is_restricted = $patient_confined && in_array($key, $restricted_while_confined, true);
        ?>
            <a href="<?= htmlspecialchars($link) ?>"
                class="sidebar-link <?= $active_page === $key ? 'active' : '' ?>"
                title="<?= htmlspecialchars($label) ?>" aria-label="<?= htmlspecialchars($label) ?>"
                <?php if ($is_restricted): ?>
                onclick="return showConfinedWarning(event, '<?= htmlspecialchars($label, ENT_QUOTES) ?>')"
                <?php endif; ?>>
                <svg class="sidebar-link-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <?= $nav_icons[$icon] ?? '' ?>
                </svg>
                <span class="sidebar-link-label"><?= htmlspecialchars($label) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="sidebar-footer">
        <div class="sidebar-user" title="<?= htmlspecialchars($_SESSION['first_name'] . ' ' . $_SESSION['last_name']) ?>">
            <div class="sidebar-user-avatar">
                <?= htmlspecialchars(strtoupper(substr($_SESSION['first_name'], 0, 1))) ?>
            </div>
            <div class="sidebar-user-info">
                <div class="sidebar-user-name"><?= htmlspecialchars($_SESSION['first_name'] . ' ' . $_SESSION['last_name']) ?></div>
                <div class="sidebar-user-role">Patient</div>
            </div>
        </div>
        <a href="../logout.php" class="sidebar-logout" title="Log Out" aria-label="Log Out">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                <polyline points="16 17 21 12 16 7"></polyline>
                <line x1="21" y1="12" x2="9" y2="12"></line>
            </svg>
            <span class="sidebar-logout-label">Log Out</span>
        </a>
    </div>
</aside>

<!-- Notification floating popover -->
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

<?php if ($patient_confined): ?>
    <!-- Warning panel shown instead of navigating, when a confined patient
         clicks Dashboard or Book Appointment from the sidebar. -->
    <div class="confined-warning-backdrop" id="confined-warning-backdrop">
        <div class="confined-warning-panel">
            <svg class="confined-warning-icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2" />
                <path d="M12 8v4m0 4h.01" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
            </svg>
            <div class="confined-warning-title" id="confined-warning-title">Not available right now</div>
            <p class="confined-warning-text">
                You're currently confined, so this section isn't available while you're admitted.
                Check <strong>My Confinement</strong> for your room, attending doctor, and progress notes.
            </p>
            <div class="confined-warning-actions">
                <button type="button" class="btn btn-secondary" onclick="closeConfinedWarning()">Cancel</button>
                <a href="confinement-dashboard.php" class="btn btn-primary">Go to My Confinement</a>
            </div>
        </div>
    </div>
    <style>
        .confined-warning-backdrop {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.45);
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }

        .confined-warning-backdrop.panel-visible {
            display: flex;
        }

        .confined-warning-panel {
            background: var(--white);
            border-radius: 14px;
            padding: 32px;
            max-width: 400px;
            width: 90%;
            text-align: center;
        }

        .confined-warning-icon {
            width: 40px;
            height: 40px;
            margin: 0 auto 14px;
            color: var(--amber);
        }

        .confined-warning-title {
            font-size: 18px;
            font-weight: 800;
            color: var(--text-dark);
            margin-bottom: 10px;
        }

        .confined-warning-text {
            font-size: 14px;
            color: var(--text-muted);
            line-height: 1.6;
            margin-bottom: 22px;
        }

        .confined-warning-actions {
            display: flex;
            gap: 10px;
            justify-content: center;
        }
    </style>
<?php endif; ?>

<script>
    <?php if ($patient_confined): ?>

        function showConfinedWarning(event, label) {
            event.preventDefault();
            document.getElementById('confined-warning-title').textContent = label + " isn't available right now";
            document.getElementById('confined-warning-backdrop').classList.add('panel-visible');
            return false;
        }

        function closeConfinedWarning() {
            document.getElementById('confined-warning-backdrop').classList.remove('panel-visible');
        }
    <?php endif; ?>
</script>

<script>
    // Toggles the off-canvas sidebar on mobile, and its dark background overlay.
    function toggleSidebar() {
        document.getElementById('sidebar').classList.toggle('sidebar-open');
        document.getElementById('sidebar-overlay').classList.toggle('overlay-visible');
    }

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