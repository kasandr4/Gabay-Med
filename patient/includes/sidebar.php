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

if (!isset($active_page)) {
    $active_page = '';
}

// Each nav item: [label, icon_path_d, link, page_key]
// page_key is compared against $active_page to apply the "active" highlight
$nav_items = [
    ['Dashboard',           'dashboard.php',         'dashboard'],
    ['Book Appointment',    'book-appointment.php',  'book-appointment'],
    ['Appointment History', 'appointment-history.php', 'appointment-history'],
    ['Prescriptions',       'prescriptions.php',     'prescriptions'],
    ['My Profile',          'profile.php',           'profile'],
];
?>
<!-- Mobile-only top bar with hamburger toggle -->
<header class="mobile-topbar">
    <button class="hamburger-btn" onclick="toggleSidebar()" aria-label="Open menu">
        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M3 6h18M3 12h18M3 18h18" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
        </svg>
    </button>
    <div class="mobile-topbar-logo">
        <img src="../logo-icon.png" alt="GabayMed logo">
        <span>GabayMed</span>
    </div>
</header>

<!-- Dark overlay shown behind the sidebar when it's open on mobile -->
<div class="sidebar-overlay" id="sidebar-overlay" onclick="toggleSidebar()"></div>

<aside class="sidebar" id="sidebar">
    <div class="sidebar-logo">
        <img src="../logo-icon.png" alt="GabayMed logo">
        <span>GabayMed</span>
    </div>

    <nav class="sidebar-nav">
        <?php foreach ($nav_items as [$label, $link, $key]): ?>
            <a href="<?= htmlspecialchars($link) ?>"
                class="sidebar-link <?= $active_page === $key ? 'active' : '' ?>">
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

<script>
    // Toggles the off-canvas sidebar on mobile, and its dark background overlay.
    function toggleSidebar() {
        document.getElementById('sidebar').classList.toggle('sidebar-open');
        document.getElementById('sidebar-overlay').classList.toggle('overlay-visible');
    }
</script>