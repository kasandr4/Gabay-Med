<?php
// includes/sidebar_collapse.php
//
// One shared implementation of the collapsible sidebar, used by every
// portal's includes/sidebar.php (pharmacist, admin, doctor, staff, capitol
// and patient), so the behaviour and the look can't drift apart between
// portals.
//
// What it gives a sidebar:
//   * a panel button at the top of the sidebar that collapses it to a slim
//     icon rail and expands it again (desktop widths only - on phones the
//     sidebar stays the off-canvas menu opened by the hamburger);
//   * the choice remembered in localStorage ('gm.sidebar'), applied before
//     the page paints so it doesn't flash open and snap shut;
//   * the sidebar's menu scrollbar hidden (it still scrolls with the wheel,
//     trackpad, touch and keyboard).
//
// How a sidebar.php uses it (three calls; the sidebar's own markup also has
// to wrap each label in <span class="nav-label"> / the patient equivalents
// and give links a title/aria-label, since the labels are hidden when
// collapsed):
//
//   require_once __DIR__ . '/../../includes/sidebar_collapse.php';
//   ...
//   sidebar_collapse_head('portal');      // once, before the sidebar markup
//   ... <aside class="sidebar"> ... sidebar_collapse_toggle(); ... </aside>
//   sidebar_collapse_script();            // once, after the sidebar markup
//
// $family picks the class names and the desktop breakpoint to match the
// portal's stylesheet:
//   'portal'  doctor / admin / pharmacist / staff / capitol
//             (assets/css/doctor-dashboard.css; desktop = wider than 768px)
//   'patient' patient portal (assets/css/dashboard.css; desktop = wider
//             than 900px)

if (!function_exists('sidebar_collapse_head')) {

    /** Early state script + the CSS. Call once, before the sidebar markup. */
    function sidebar_collapse_head(string $family = 'portal'): void
    {
        ?>
<script>
    // Apply the remembered collapsed state BEFORE the sidebar is painted.
    try {
        if (localStorage.getItem('gm.sidebar') === 'collapsed') {
            document.documentElement.classList.add('sb-collapsed');
        }
    } catch (e) {}
</script>
<style>
<?php if ($family === 'patient'): ?>
    /* ---- Patient portal ---- */
    .sidebar .sidebar-nav {
        overflow-y: auto;
        scrollbar-width: none;
        -ms-overflow-style: none;
    }

    .sidebar .sidebar-nav::-webkit-scrollbar {
        display: none;
        width: 0;
        height: 0;
    }

    .sb-toolbar {
        display: none;
    }

    .sidebar-link {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .sidebar-link-icon,
    .sidebar-logout svg {
        flex-shrink: 0;
    }

    .sidebar-logout {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
    }

    @media (min-width: 901px) {
        .sidebar {
            transition: width 0.22s ease;
        }

        .sb-toolbar {
            display: flex;
            align-items: center;
            padding: 14px 14px 0;
        }

        .sb-toggle-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 34px;
            height: 34px;
            padding: 0;
            border: none;
            border-radius: 8px;
            background: transparent;
            color: var(--sidebar-dark-text);
            transition: background 0.15s ease, color 0.15s ease;
        }

        .sb-toggle-btn:hover {
            background: rgba(255, 255, 255, 0.08);
            color: var(--sidebar-dark-text-strong);
        }

        .sb-toggle-btn:focus-visible {
            outline: 2px solid var(--primary);
            outline-offset: 2px;
        }

        /* ---- Collapsed: icon rail ---- */
        html.sb-collapsed .sidebar {
            width: 76px;
        }

        html.sb-collapsed .sb-toolbar {
            justify-content: center;
            padding: 14px 0 0;
        }

        html.sb-collapsed .sidebar-logo {
            flex-direction: column;
            justify-content: center;
            gap: 10px;
            padding: 16px 0;
        }

        html.sb-collapsed .sidebar-logo .brand-text,
        html.sb-collapsed .sidebar-link-label,
        html.sb-collapsed .sidebar-user-info,
        html.sb-collapsed .sidebar-logout-label {
            display: none;
        }

        html.sb-collapsed .sidebar-nav {
            padding: 16px 10px;
        }

        html.sb-collapsed .sidebar-link {
            justify-content: center;
            gap: 0;
            padding: 11px 0;
        }

        html.sb-collapsed .sidebar-footer {
            padding: 16px 10px;
        }

        html.sb-collapsed .sidebar-user {
            justify-content: center;
            gap: 0;
        }

        html.sb-collapsed .sidebar-logout {
            gap: 0;
            padding: 9px 0;
        }
    }
<?php else: ?>
    /* ---- Doctor / admin / pharmacist / staff / capitol ---- */
    /* The menu list scrolls when the window is shorter than the menu, but the
       scrollbar itself is hidden. */
    .sidebar .sidebar-nav {
        scrollbar-width: none;
        -ms-overflow-style: none;
    }

    .sidebar .sidebar-nav::-webkit-scrollbar {
        display: none;
        width: 0;
        height: 0;
    }

    .sb-toolbar {
        display: none;
    }

    @media (min-width: 769px) {
        .sidebar {
            transition: width 0.22s ease, padding 0.22s ease;
        }

        .sb-toolbar {
            display: flex;
            align-items: center;
            margin: -6px 0 14px;
            padding: 0 2px;
        }

        .sb-toggle-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 34px;
            height: 34px;
            padding: 0;
            border: none;
            border-radius: var(--radius-sm);
            background: transparent;
            color: var(--sidebar-text);
            transition: background 0.15s ease, color 0.15s ease;
        }

        .sb-toggle-btn:hover {
            background: var(--sidebar-hover-bg);
            color: var(--sidebar-text-active);
        }

        .sb-toggle-btn:focus-visible {
            outline: 2px solid var(--sidebar-accent);
            outline-offset: 2px;
        }

        /* ---- Collapsed: icon rail ---- */
        html.sb-collapsed .sidebar {
            width: 76px;
            padding: 24px 12px;
        }

        html.sb-collapsed .sb-toolbar {
            justify-content: center;
            padding: 0;
        }

        html.sb-collapsed .sidebar-top {
            flex-direction: column;
            gap: 10px;
            margin-bottom: 20px;
            padding: 0;
        }

        html.sb-collapsed .brand-name,
        html.sb-collapsed .nav-section-title,
        html.sb-collapsed .nav-label,
        html.sb-collapsed .user-info,
        html.sb-collapsed .logout-label {
            display: none;
        }

        html.sb-collapsed .nav-link {
            position: relative;
            justify-content: center;
            gap: 0;
            padding: 11px 0;
        }

        /* Staff menu counts (e.g. pending items) become a small dot-badge on the icon. */
        html.sb-collapsed .nav-badge {
            position: absolute;
            top: 3px;
            right: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 16px;
            height: 16px;
            padding: 0 3px;
            border-radius: 999px;
            background: var(--red);
            color: #ffffff;
            font-size: 10px;
            font-weight: 800;
        }

        html.sb-collapsed .nav-section-group:not(:last-child) {
            padding-bottom: 12px;
        }

        html.sb-collapsed .user-card {
            justify-content: center;
            gap: 0;
            padding: 16px 0 10px;
        }

        html.sb-collapsed .logout-btn {
            gap: 0;
            padding: 10px 0;
        }
    }
<?php endif; ?>
</style>
<?php
    }

    /** The collapse / expand button. Call once, as the first thing inside <aside class="sidebar">. */
    function sidebar_collapse_toggle(): void
    {
        ?>
    <div class="sb-toolbar">
        <button type="button" class="sb-toggle-btn" id="sbCollapseBtn" aria-controls="sidebar" aria-expanded="true" aria-label="Collapse sidebar" title="Collapse sidebar">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <rect x="3" y="3" width="18" height="18" rx="2"></rect>
                <line x1="9" y1="3" x2="9" y2="21"></line>
            </svg>
        </button>
    </div>

<?php
    }

    /** The click handler. Call once, after the sidebar markup. */
    function sidebar_collapse_script(): void
    {
        ?>
<script>
    // Desktop collapse / expand. The collapsed state is the 'sb-collapsed'
    // class on <html> (set early, in sidebar_collapse_head, from
    // localStorage); the CSS that turns it into an icon rail only applies at
    // desktop widths.
    (function() {
        const KEY = 'gm.sidebar';
        const root = document.documentElement;
        const btn = document.getElementById('sbCollapseBtn');
        if (!btn) return;

        function render() {
            const collapsed = root.classList.contains('sb-collapsed');
            const label = collapsed ? 'Expand sidebar' : 'Collapse sidebar';
            btn.setAttribute('aria-expanded', String(!collapsed));
            btn.setAttribute('aria-label', label);
            btn.title = label;
        }

        btn.addEventListener('click', function() {
            const collapsed = root.classList.toggle('sb-collapsed');
            try {
                localStorage.setItem(KEY, collapsed ? 'collapsed' : 'expanded');
            } catch (e) {}
            render();
        });
        render();
    })();
</script>
<?php
    }
}
