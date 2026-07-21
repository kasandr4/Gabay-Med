<?php
// patient/includes/sidebar.php
//
// Shared sidebar navigation for ALL patient-facing pages.
// Include this AFTER auth_guard.php has already run require_role('patient').
//
// USAGE: set $active_page before including this file, so the matching
// nav item gets highlighted. Example:
//   $active_page = 'dashboard';
//   include 'includes/sidebar.php';

require_once __DIR__ . '/../../includes/notifications.php';

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

// Each nav item: [label, icon_path_d, link, page_key]
// page_key is compared against $active_page to apply the "active" highlight
$nav_items = [
    ['Dashboard',           'dashboard.php',         'dashboard'],
    ['Book Appointment',    'book-appointment.php',  'book-appointment'],
    ['Appointment History', 'appointment-history.php', 'appointment-history'],
    ['Prescriptions',       'prescriptions.php',     'prescriptions'],
    ['My Confinement',      'confinement-dashboard.php', 'confinement'],
    ['My Profile',          'profile.php',           'profile'],
];
?>
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
    <div class="sidebar-logo">
        <img src="../logo-icon.png" alt="GabayMed logo">
        <span>GabayMed</span>
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
        <?php foreach ($nav_items as [$label, $link, $key]):
            $is_restricted = $patient_confined && in_array($key, $restricted_while_confined, true);
        ?>
            <a href="<?= htmlspecialchars($link) ?>"
                class="sidebar-link <?= $active_page === $key ? 'active' : '' ?>"
                <?php if ($is_restricted): ?>
                onclick="return showConfinedWarning(event, '<?= htmlspecialchars($label, ENT_QUOTES) ?>')"
                <?php endif; ?>>
                <?= htmlspecialchars($label) ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="sidebar-footer">
        <div class="sidebar-user">
            <div class="sidebar-user-avatar">
                <?= htmlspecialchars(strtoupper(substr($_SESSION['first_name'], 0, 1))) ?>
            </div>
            <div class="sidebar-user-info">
                <div class="sidebar-user-name"><?= htmlspecialchars($_SESSION['first_name'] . ' ' . $_SESSION['last_name']) ?></div>
                <div class="sidebar-user-role">Patient</div>
            </div>
        </div>
        <a href="../logout.php" class="sidebar-logout">Log Out</a>
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