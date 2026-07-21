// assets/js/user-management.js
// GabayMed — Admin Portal: User Management page.
// UI ONLY: everything here is DOM/state manipulation over the placeholder
// rows rendered by admin/user-management.php. There is no fetch() to a
// backend anywhere in this file — actions like Block/Deactivate/Delete
// only update the row on screen and show a toast, exactly like the
// no-backend patterns already used by hospital-reports.js /
// reconciliation.js. Swap in real requests once the API layer exists.

(function () {
    'use strict';

    // umUsers is defined inline in user-management.php (same pattern as
    // hospital-census.php's `censusData`) so the drawer/modal don't need a
    // second round trip for placeholder data.
    const usersById = (typeof umUsers !== 'undefined') ? umUsers : {};

    /* ============================= Toasts ============================= */

    function showToast(message, isDanger) {
        let host = document.getElementById('umToastHost');
        if (!host) {
            host = document.createElement('div');
            host.className = 'um-toast-host';
            host.id = 'umToastHost';
            document.body.appendChild(host);
        }
        const toast = document.createElement('div');
        toast.className = 'um-toast' + (isDanger ? ' um-toast-danger' : '');
        toast.textContent = message;
        host.appendChild(toast);
        requestAnimationFrame(() => toast.classList.add('um-toast-show'));
        setTimeout(() => {
            toast.classList.remove('um-toast-show');
            setTimeout(() => toast.remove(), 250);
        }, 3200);
    }

    /* ======================= Tabs / Search / Filters ==================== */

    const tableWrap = document.getElementById('umTableWrap');
    const table = document.getElementById('umUserTable');
    const tbody = table ? table.querySelector('tbody') : null;
    const noResults = document.getElementById('umNoResults');
    const rowCountEl = document.getElementById('umRowCount');

    const searchInput = document.getElementById('umSearchInput');
    const roleFilter = document.getElementById('umRoleFilter');
    const departmentFilter = document.getElementById('umDepartmentFilter');
    const statusFilter = document.getElementById('umStatusFilter');
    const dateFilter = document.getElementById('umDateFilter');
    const sortSelect = document.getElementById('umSortSelect');
    const resetBtn = document.getElementById('umResetFiltersBtn');
    const tabButtons = document.querySelectorAll('.um-tabs .tab-btn');

    let activeTabRole = 'all';
    let rows = table ? Array.from(table.querySelectorAll('.um-row')) : [];

    function applyFilters() {
        if (!rows.length) return;
        const term = searchInput ? searchInput.value.trim().toLowerCase() : '';
        const role = roleFilter ? roleFilter.value : 'all';
        const department = departmentFilter ? departmentFilter.value : 'all';
        const status = statusFilter ? statusFilter.value : 'all';
        const date = dateFilter ? dateFilter.value : '';
        let visible = 0;

        rows.forEach(function (row) {
            const rowRole = row.getAttribute('data-role') || '';
            const rowDept = row.getAttribute('data-department') || '';
            const rowStatus = row.getAttribute('data-status') || '';
            const rowDate = row.getAttribute('data-date') || '';
            const rowSearch = row.getAttribute('data-search') || '';

            const matchesTab = activeTabRole === 'all' || rowRole === activeTabRole;
            const matchesRole = role === 'all' || rowRole === role;
            const matchesDept = department === 'all' || rowDept === department;
            const matchesStatus = status === 'all' || rowStatus === status;
            const matchesDate = !date || rowDate === date;
            const matchesSearch = !term || rowSearch.indexOf(term) !== -1;

            const isVisible = matchesTab && matchesRole && matchesDept && matchesStatus && matchesDate && matchesSearch;
            row.hidden = !isVisible;
            if (isVisible) visible++;
        });

        if (noResults) noResults.hidden = visible !== 0;
        if (tableWrap) tableWrap.classList.toggle('um-has-results', visible !== 0);
        if (rowCountEl) rowCountEl.textContent = visible + (visible === 1 ? ' user' : ' users');
    }

    function applySort() {
        if (!tbody || !sortSelect) return;
        const mode = sortSelect.value;
        const sorted = rows.slice().sort(function (a, b) {
            if (mode === 'name-asc') return a.getAttribute('data-name').localeCompare(b.getAttribute('data-name'));
            if (mode === 'name-desc') return b.getAttribute('data-name').localeCompare(a.getAttribute('data-name'));
            if (mode === 'date-desc') return b.getAttribute('data-date').localeCompare(a.getAttribute('data-date'));
            if (mode === 'date-asc') return a.getAttribute('data-date').localeCompare(b.getAttribute('data-date'));
            if (mode === 'status') return a.getAttribute('data-status').localeCompare(b.getAttribute('data-status'));
            return 0;
        });
        sorted.forEach(function (row) { tbody.appendChild(row); });
        rows = sorted;
    }

    if (searchInput) searchInput.addEventListener('input', applyFilters);
    if (roleFilter) roleFilter.addEventListener('change', applyFilters);
    if (departmentFilter) departmentFilter.addEventListener('change', applyFilters);
    if (statusFilter) statusFilter.addEventListener('change', applyFilters);
    if (dateFilter) dateFilter.addEventListener('change', applyFilters);
    if (sortSelect) sortSelect.addEventListener('change', function () {
        applySort();
        applyFilters();
    });

    if (resetBtn) {
        resetBtn.addEventListener('click', function () {
            if (searchInput) searchInput.value = '';
            if (roleFilter) roleFilter.value = 'all';
            if (departmentFilter) departmentFilter.value = 'all';
            if (statusFilter) statusFilter.value = 'all';
            if (dateFilter) dateFilter.value = '';
            if (sortSelect) sortSelect.value = 'date-desc';
            activeTabRole = 'all';
            tabButtons.forEach(function (btn) { btn.classList.toggle('active', btn.getAttribute('data-role') === 'all'); });
            applySort();
            applyFilters();
        });
    }

    tabButtons.forEach(function (btn) {
        btn.addEventListener('click', function () {
            tabButtons.forEach(function (b) { b.classList.remove('active'); });
            btn.classList.add('active');
            activeTabRole = btn.getAttribute('data-role') || 'all';
            if (roleFilter) roleFilter.value = 'all';
            applyFilters();
        });
    });

    /* ============================== Refresh ============================= */

    const refreshBtn = document.getElementById('umRefreshBtn');
    if (refreshBtn && tableWrap) {
        refreshBtn.addEventListener('click', function () {
            tableWrap.classList.add('is-loading');
            refreshBtn.classList.add('is-spinning');
            setTimeout(function () {
                tableWrap.classList.remove('is-loading');
                refreshBtn.classList.remove('is-spinning');
                applyFilters();
                showToast('User list refreshed.');
            }, 650);
        });
    }

    /* ============================= Export CSV ============================ */

    const exportBtn = document.getElementById('umExportBtn');
    if (exportBtn) {
        exportBtn.addEventListener('click', function () {
            const visibleRows = rows.filter(function (r) { return !r.hidden; });
            if (!visibleRows.length) {
                showToast('No users to export for the current filters.', true);
                return;
            }
            const header = ['Name', 'Email', 'Phone', 'Role', 'Department', 'Status', 'Last Login'];
            const lines = [header.join(',')];
            visibleRows.forEach(function (row) {
                const id = row.getAttribute('data-user-id');
                const u = usersById[id];
                if (!u) return;
                const cells = [u.full_name, u.email, u.phone, u.role_label, u.department, u.status_label, u.last_login]
                    .map(function (v) { return '"' + String(v).replace(/"/g, '""') + '"'; });
                lines.push(cells.join(','));
            });
            const blob = new Blob([lines.join('\n')], { type: 'text/csv;charset=utf-8;' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'gabaymed-users-' + new Date().toISOString().slice(0, 10) + '.csv';
            document.body.appendChild(a);
            a.click();
            a.remove();
            URL.revokeObjectURL(url);
            showToast('Exported ' + visibleRows.length + ' user' + (visibleRows.length === 1 ? '' : 's') + ' to CSV.');
        });
    }

    /* ========================== Row kebab dropdown ======================= */

    document.addEventListener('click', function (e) {
        const trigger = e.target.closest('.um-kebab-btn');
        document.querySelectorAll('.um-kebab-wrap.um-menu-open').forEach(function (wrap) {
            if (!trigger || wrap !== trigger.closest('.um-kebab-wrap')) {
                wrap.classList.remove('um-menu-open');
            }
        });
        if (trigger) {
            e.stopPropagation();
            trigger.closest('.um-kebab-wrap').classList.toggle('um-menu-open');
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.um-kebab-wrap.um-menu-open').forEach(function (w) { w.classList.remove('um-menu-open'); });
        }
    });

    /* ============================ View drawer ============================ */

    const drawerBackdrop = document.getElementById('umDrawerBackdrop');
    const drawerCloseBtn = document.getElementById('umDrawerCloseBtn');
    let currentDrawerUserId = null;

    const statusPillClass = {
        active: 'status-um-active', inactive: 'status-um-inactive', blocked: 'status-um-blocked',
        pending: 'status-um-pending', deactivated: 'status-um-deactivated'
    };

    function openDrawer(userId) {
        const u = usersById[userId];
        if (!u) return;
        currentDrawerUserId = userId;

        document.getElementById('umDrawerAvatar').textContent = u.initials;
        document.getElementById('umDrawerName').textContent = u.full_name;
        document.getElementById('umDrawerSub').textContent = u.role_label + ' · ' + u.department;

        const statusEl = document.getElementById('umDrawerStatus');
        statusEl.textContent = u.status_label;
        statusEl.className = 'status-pill ' + (statusPillClass[u.status] || '');

        document.getElementById('umDrawerEmail').textContent = u.email;
        document.getElementById('umDrawerPhone').textContent = u.phone;
        document.getElementById('umDrawerAddress').textContent = u.address;
        document.getElementById('umDrawerGender').textContent = u.gender;
        document.getElementById('umDrawerBirthdate').textContent = u.birthdate;
        document.getElementById('umDrawerUsername').textContent = u.username;
        document.getElementById('umDrawerRole').textContent = u.role_label;
        document.getElementById('umDrawerDepartment').textContent = u.department;
        document.getElementById('umDrawerRegistered').textContent = u.date_registered;
        document.getElementById('umDrawerLastLogin').textContent = u.last_login;

        const timeline = document.getElementById('umDrawerActivity');
        timeline.innerHTML = '';
        if (u.activity && u.activity.length) {
            u.activity.forEach(function (item) {
                const li = document.createElement('li');
                li.className = 'activity-item';
                li.innerHTML = '<span class="activity-dot"></span><div class="activity-body"><p></p><span class="activity-time"></span></div>';
                li.querySelector('p').textContent = item.text;
                li.querySelector('.activity-time').textContent = item.time;
                timeline.appendChild(li);
            });
        } else {
            timeline.innerHTML = '<li class="activity-item"><div class="activity-body"><p>No recent activity.</p></div></li>';
        }

        const printLink = document.getElementById('umDrawerPrintBtn');
        if (printLink) printLink.setAttribute('data-user-id', userId);

        drawerBackdrop.classList.add('active');
        document.body.classList.add('drawer-open');
    }

    function closeDrawer() {
        drawerBackdrop.classList.remove('active');
        document.body.classList.remove('drawer-open');
    }

    if (drawerCloseBtn) drawerCloseBtn.addEventListener('click', closeDrawer);
    if (drawerBackdrop) drawerBackdrop.addEventListener('click', function (e) {
        if (e.target === drawerBackdrop) closeDrawer();
    });

    const printBtn = document.getElementById('umDrawerPrintBtn');
    if (printBtn) {
        printBtn.addEventListener('click', function () {
            const id = printBtn.getAttribute('data-user-id');
            if (id) window.open('print-user-profile.php?user_id=' + encodeURIComponent(id), '_blank');
        });
    }

    /* ======================= Add / Edit User modal ======================= */

    const userModalBackdrop = document.getElementById('umUserModal');
    const userForm = document.getElementById('umUserForm');
    const userModalTitle = document.getElementById('umUserModalTitle');
    const userModalSubmitBtn = document.getElementById('umUserModalSubmitBtn');
    const addUserBtn = document.getElementById('umAddUserBtn');

    function openUserModal(mode, userId) {
        userForm.reset();
        userForm.querySelector('[name="status"]').value = 'pending';
        if (mode === 'edit' && userId && usersById[userId]) {
            const u = usersById[userId];
            userModalTitle.textContent = 'Edit User';
            userModalSubmitBtn.textContent = 'Save Changes';
            userForm.querySelector('[name="first_name"]').value = u.first_name;
            userForm.querySelector('[name="last_name"]').value = u.last_name;
            userForm.querySelector('[name="email"]').value = u.email;
            userForm.querySelector('[name="phone"]').value = u.phone;
            userForm.querySelector('[name="username"]').value = u.username;
            userForm.querySelector('[name="role"]').value = u.role;
            userForm.querySelector('[name="department"]').value = u.department;
            userForm.querySelector('[name="status"]').value = u.status;
        } else {
            userModalTitle.textContent = 'Add User';
            userModalSubmitBtn.textContent = 'Create User';
        }
        userForm.setAttribute('data-mode', mode);
        userForm.setAttribute('data-user-id', userId || '');
        userModalBackdrop.classList.add('active');
        document.body.classList.add('modal-open');
    }

    function closeUserModal() {
        userModalBackdrop.classList.remove('active');
        document.body.classList.remove('modal-open');
    }

    if (addUserBtn) addUserBtn.addEventListener('click', function () { openUserModal('add', null); });

    document.querySelectorAll('[data-close-modal]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const id = btn.getAttribute('data-close-modal');
            const backdrop = document.getElementById(id);
            if (backdrop) {
                backdrop.classList.remove('active');
                document.body.classList.remove('modal-open');
            }
        });
    });

    document.querySelectorAll('.modal-backdrop').forEach(function (backdrop) {
        backdrop.addEventListener('click', function (e) {
            if (e.target === backdrop) {
                backdrop.classList.remove('active');
                document.body.classList.remove('modal-open');
            }
        });
    });

    if (userForm) {
        userForm.addEventListener('submit', function (e) {
            e.preventDefault();
            const mode = userForm.getAttribute('data-mode');
            const firstName = userForm.querySelector('[name="first_name"]').value.trim() || 'New';
            const lastName = userForm.querySelector('[name="last_name"]').value.trim() || 'User';
            closeUserModal();
            showToast(mode === 'edit'
                ? 'Changes saved for ' + firstName + ' ' + lastName + '.'
                : firstName + ' ' + lastName + ' was added (UI only — not saved).');
        });
    }

    /* ===================== Confirmation modals (shared) =================== */

    const confirmModals = {
        reset: document.getElementById('umResetPasswordModal'),
        block: document.getElementById('umBlockModal'),
        deactivate: document.getElementById('umDeactivateModal'),
        delete: document.getElementById('umDeleteModal'),
    };

    let confirmTargetUserId = null;
    let confirmTargetRow = null;

    function fillConfirmModal(type, u) {
        const modal = confirmModals[type];
        if (!modal) return;
        const nameEl = modal.querySelector('.um-confirm-user-name');
        const subEl = modal.querySelector('.um-confirm-user-sub');
        const avatarEl = modal.querySelector('.um-confirm-user-avatar');
        if (nameEl) nameEl.textContent = u.full_name;
        if (subEl) subEl.textContent = u.role_label + ' · ' + u.department;
        if (avatarEl) avatarEl.textContent = u.initials;
    }

    function openConfirmModal(type, userId, row) {
        const u = usersById[userId];
        const modal = confirmModals[type];
        if (!u || !modal) return;
        confirmTargetUserId = userId;
        confirmTargetRow = row;
        fillConfirmModal(type, u);
        modal.classList.add('active');
        document.body.classList.add('modal-open');
    }

    function closeConfirmModal(type) {
        const modal = confirmModals[type];
        if (modal) {
            modal.classList.remove('active');
            document.body.classList.remove('modal-open');
        }
    }

    function updateRowStatus(row, status, label) {
        if (!row) return;
        row.setAttribute('data-status', status);
        const pill = row.querySelector('.um-status-pill');
        if (pill) {
            pill.className = 'status-pill um-status-pill ' + (statusPillClass[status] || '');
            pill.textContent = label;
        }
    }

    document.querySelectorAll('[data-confirm-action]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const type = btn.getAttribute('data-confirm-action');
            closeConfirmModal(type);
            if (!confirmTargetUserId) return;
            const u = usersById[confirmTargetUserId];

            if (type === 'reset') {
                showToast('Password reset link sent to ' + (u ? u.email : 'user') + '.');
            } else if (type === 'block') {
                updateRowStatus(confirmTargetRow, 'blocked', 'Blocked');
                showToast((u ? u.full_name : 'User') + ' has been blocked.', true);
            } else if (type === 'deactivate') {
                updateRowStatus(confirmTargetRow, 'deactivated', 'Deactivated');
                showToast((u ? u.full_name : 'User') + ' has been deactivated.', true);
            } else if (type === 'delete') {
                if (confirmTargetRow) confirmTargetRow.remove();
                showToast((u ? u.full_name : 'User') + ' was deleted (UI only — not saved).', true);
                applyFilters();
            }
            if (drawerBackdrop && drawerBackdrop.classList.contains('active')) closeDrawer();
            confirmTargetUserId = null;
            confirmTargetRow = null;
        });
    });

    /* ===================== Wire up row + kebab menu actions =============== */

    document.querySelectorAll('.um-row').forEach(function (row) {
        row.addEventListener('click', function (e) {
            if (e.target.closest('.um-row-actions')) return;
            openDrawer(row.getAttribute('data-user-id'));
        });
        row.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.target.closest('.um-row-actions')) {
                openDrawer(row.getAttribute('data-user-id'));
            }
        });
    });

    document.addEventListener('click', function (e) {
        const actionBtn = e.target.closest('[data-um-action]');
        if (!actionBtn) return;
        e.stopPropagation();
        const action = actionBtn.getAttribute('data-um-action');
        const row = actionBtn.closest('.um-row');
        const userId = row ? row.getAttribute('data-user-id') : null;

        document.querySelectorAll('.um-kebab-wrap.um-menu-open').forEach(function (w) { w.classList.remove('um-menu-open'); });

        if (action === 'view') openDrawer(userId);
        else if (action === 'edit') openUserModal('edit', userId);
        else if (action === 'reset') openConfirmModal('reset', userId, row);
        else if (action === 'block') openConfirmModal('block', userId, row);
        else if (action === 'deactivate') openConfirmModal('deactivate', userId, row);
        else if (action === 'delete') openConfirmModal('delete', userId, row);
    });

    // Drawer's own Edit / Reset Password buttons act on whichever user is
    // currently open in the drawer.
    const drawerEditBtn = document.getElementById('umDrawerEditBtn');
    const drawerResetBtn = document.getElementById('umDrawerResetBtn');
    if (drawerEditBtn) drawerEditBtn.addEventListener('click', function () {
        if (currentDrawerUserId) openUserModal('edit', currentDrawerUserId);
    });
    if (drawerResetBtn) drawerResetBtn.addEventListener('click', function () {
        if (currentDrawerUserId) {
            const row = table.querySelector('.um-row[data-user-id="' + currentDrawerUserId + '"]');
            openConfirmModal('reset', currentDrawerUserId, row);
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        if (drawerBackdrop && drawerBackdrop.classList.contains('active')) closeDrawer();
        document.querySelectorAll('.modal-backdrop.active').forEach(function (m) {
            m.classList.remove('active');
            document.body.classList.remove('modal-open');
        });
    });

    // Support deep links from the Dashboard's Quick Actions, e.g.
    // user-management.php?tab=doctors&action=add — selects the matching
    // tab and/or opens the Add User modal pre-set to that role.
    (function handleQueryParams() {
        const params = new URLSearchParams(window.location.search);
        const tab = params.get('tab');
        const action = params.get('action');

        if (tab) {
            const roleMap = { patients: 'patient', doctors: 'doctor', pharmacists: 'pharmacist', administrators: 'admin', staff: 'staff' };
            const role = roleMap[tab] || tab;
            const matchingTab = document.querySelector('.um-tabs .tab-btn[data-role="' + role + '"]');
            if (matchingTab) matchingTab.click();
        }

        if (action === 'add') {
            openUserModal('add', null);
            if (tab) {
                const roleMap = { patients: 'patient', doctors: 'doctor', pharmacists: 'pharmacist', administrators: 'admin', staff: 'staff' };
                const role = roleMap[tab] || tab;
                const roleSelect = userForm.querySelector('[name="role"]');
                if (roleSelect && role) roleSelect.value = role;
            }
        }
    })();

    // Initial paint.
    applyFilters();
})();
