// assets/js/user-management.js
// GabayMed — Admin Portal: User Management page.
// Posts to admin/user-management-actions.php (create_user, update_user,
// toggle_active, set_status, reset_password, archive_user, restore_user).
// After any mutating action, the page reloads (preserving ?view=) so the
// stat cards / tab counts / donut chart — which are all server-computed —
// stay correct without duplicating that logic in JS. A toast message
// survives the reload via sessionStorage.

(function () {
    'use strict';

    // umUsers / umCurrentView are defined inline in user-management.php.
    const usersById = (typeof umUsers !== 'undefined') ? umUsers : {};
    const currentView = (typeof umCurrentView !== 'undefined') ? umCurrentView : 'active';
    const csrfTokenEl = document.getElementById('umCsrfToken');
    const csrfToken = csrfTokenEl ? csrfTokenEl.value : '';

    const statusPillClass = {
        active: 'status-um-active', pending: 'status-um-pending', blocked: 'status-um-blocked',
        confined: 'status-um-confined', deceased: 'status-um-deceased',
        deactivated: 'status-um-deactivated', archived: 'status-um-archived'
    };
    const statusLabels = {
        active: 'Active', pending: 'Pending', blocked: 'Blocked',
        confined: 'Confined', deceased: 'Deceased', deactivated: 'Deactivated', archived: 'Archived'
    };

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

    // Show any toast queued before the last reload (see queueToastAndReload).
    (function showPendingToast() {
        const pending = sessionStorage.getItem('umPendingToast');
        if (!pending) return;
        sessionStorage.removeItem('umPendingToast');
        try {
            const data = JSON.parse(pending);
            showToast(data.message, data.isDanger);
        } catch (e) { /* ignore malformed entry */ }
    })();

    function queueToastAndReload(message, isDanger) {
        sessionStorage.setItem('umPendingToast', JSON.stringify({ message: message, isDanger: !!isDanger }));
        window.location.reload();
    }

    /* ============================ Backend calls =========================== */

    function postAction(params) {
        const body = new URLSearchParams(params);
        body.set('csrf_token', csrfToken);
        return fetch('user-management-actions.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        }).then(function (res) {
            return res.json().then(function (data) { return { ok: res.ok, data: data }; });
        }).catch(function () {
            return { ok: false, data: { success: false, error: 'Network error. Please check your connection and try again.' } };
        });
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
    // A real reload — filters/sort reset, but the data is guaranteed fresh.

    const refreshBtn = document.getElementById('umRefreshBtn');
    if (refreshBtn) {
        refreshBtn.addEventListener('click', function () {
            window.location.reload();
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
            const header = ['Name', 'Email', 'Phone', 'Role', 'Department', 'Status', 'Date Registered'];
            const lines = [header.join(',')];
            visibleRows.forEach(function (row) {
                const id = row.getAttribute('data-user-id');
                const u = usersById[id];
                if (!u) return;
                const cells = [u.full_name, u.email || '', u.phone, u.role_label, u.department_name, u.status_label, u.date_registered]
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

    function openDrawer(userId) {
        const u = usersById[userId];
        if (!u) return;
        currentDrawerUserId = userId;

        document.getElementById('umDrawerAvatar').textContent = u.initials;
        document.getElementById('umDrawerName').textContent = u.full_name;
        document.getElementById('umDrawerSub').textContent = u.role_label + ' · ' + u.department_name;

        const statusEl = document.getElementById('umDrawerStatus');
        statusEl.textContent = u.status_label;
        statusEl.className = 'status-pill ' + (statusPillClass[u.status_key] || '');

        document.getElementById('umDrawerEmail').textContent = u.email || '—';
        document.getElementById('umDrawerPhone').textContent = u.phone;
        document.getElementById('umDrawerAddress').textContent = u.address || '—';
        document.getElementById('umDrawerGender').textContent = u.sex ? (u.sex.charAt(0).toUpperCase() + u.sex.slice(1)) : '—';
        document.getElementById('umDrawerBirthdate').textContent = u.birthdate || '—';
        document.getElementById('umDrawerRole').textContent = u.role_label;
        document.getElementById('umDrawerDepartment').textContent = u.department_name;
        document.getElementById('umDrawerRegistered').textContent = u.date_registered;

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

        // Archived users can only be viewed/restored, not edited or reset.
        const editBtn = document.getElementById('umDrawerEditBtn');
        const resetBtnDrawer = document.getElementById('umDrawerResetBtn');
        if (editBtn) editBtn.hidden = (currentView === 'archived');
        if (resetBtnDrawer) resetBtnDrawer.hidden = (currentView === 'archived');

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
    const formErrorEl = document.getElementById('umFormError');
    const passwordRow = userForm ? userForm.querySelector('.um-form-row-password') : null;
    const passwordNote = userForm ? userForm.querySelector('.um-form-password-note') : null;
    const staffTypeRow = userForm ? userForm.querySelector('.um-form-row-staff-type') : null;
    const roleSelectEl = userForm ? userForm.querySelector('[name="role"]') : null;

    // Staff Type only means anything for role=staff — see
    // 013_staff_subtype.sql. Toggled on every role change, not just on
    // modal open, so switching the dropdown live shows/hides it too.
    function syncStaffTypeVisibility() {
        if (!staffTypeRow || !roleSelectEl) return;
        staffTypeRow.hidden = (roleSelectEl.value !== 'staff');
    }
    if (roleSelectEl) roleSelectEl.addEventListener('change', syncStaffTypeVisibility);

    function showFormError(message) {
        if (!formErrorEl) return;
        formErrorEl.textContent = message;
        formErrorEl.hidden = false;
    }

    function clearFormError() {
        if (!formErrorEl) return;
        formErrorEl.hidden = true;
        formErrorEl.textContent = '';
    }

    function openUserModal(mode, userId) {
        userForm.reset();
        clearFormError();
        const u = (mode === 'edit' && userId) ? usersById[userId] : null;

        if (u) {
            userModalTitle.textContent = 'Edit User';
            userModalSubmitBtn.textContent = 'Save Changes';
            userForm.querySelector('[name="first_name"]').value = u.first_name;
            userForm.querySelector('[name="last_name"]').value = u.last_name;
            userForm.querySelector('[name="email"]').value = u.email || '';
            userForm.querySelector('[name="phone"]').value = u.phone;
            userForm.querySelector('[name="role"]').value = u.role;
            userForm.querySelector('[name="department_id"]').value = u.department_id || '';
            userForm.querySelector('[name="staff_type"]').value = u.staff_type || 'front_desk';
            userForm.querySelector('[name="sex"]').value = u.sex || '';
            userForm.querySelector('[name="birthdate"]').value = u.birthdate || '';
            userForm.querySelector('[name="address"]').value = u.address || '';
            userForm.querySelector('[name="action"]').value = 'update_user';
            if (passwordRow) passwordRow.hidden = true;
            if (passwordNote) passwordNote.hidden = false;
        } else {
            userModalTitle.textContent = 'Add User';
            userModalSubmitBtn.textContent = 'Create User';
            userForm.querySelector('[name="action"]').value = 'create_user';
            if (passwordRow) passwordRow.hidden = false;
            if (passwordNote) passwordNote.hidden = true;
        }
        userForm.querySelector('[name="user_id"]').value = userId || '';
        syncStaffTypeVisibility();
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
            clearFormError();
            const mode = userForm.querySelector('[name="action"]').value === 'update_user' ? 'edit' : 'add';
            const submitBtn = userModalSubmitBtn;
            const originalLabel = submitBtn.textContent;
            submitBtn.disabled = true;
            submitBtn.textContent = mode === 'edit' ? 'Saving…' : 'Creating…';

            const formData = new FormData(userForm);
            const params = {};
            formData.forEach(function (value, key) { params[key] = value; });

            postAction(params).then(function (result) {
                submitBtn.disabled = false;
                submitBtn.textContent = originalLabel;
                if (result.data && result.data.success) {
                    closeUserModal();
                    queueToastAndReload(result.data.message || (mode === 'edit' ? 'Changes saved.' : 'User created.'));
                } else {
                    showFormError((result.data && result.data.error) || 'Something went wrong. Please try again.');
                }
            });
        });
    }

    /* ===================== Confirmation modals (shared) =================== */

    const confirmModals = {
        reset: document.getElementById('umResetPasswordModal'),
        status: document.getElementById('umSetStatusModal'),
        'toggle-active': document.getElementById('umDeactivateModal'),
        archive: document.getElementById('umArchiveModal'),
        restore: document.getElementById('umRestoreModal'),
    };

    let confirmTargetUserId = null;
    let confirmTargetStatusValue = null;

    function fillConfirmModal(type, u) {
        const modal = confirmModals[type];
        if (!modal) return;
        const nameEl = modal.querySelector('.um-confirm-user-name');
        const subEl = modal.querySelector('.um-confirm-user-sub');
        const avatarEl = modal.querySelector('.um-confirm-user-avatar');
        if (nameEl) nameEl.textContent = u.full_name;
        if (subEl) subEl.textContent = u.role_label + ' · ' + u.department_name;
        if (avatarEl) avatarEl.textContent = u.initials;
    }

    function openConfirmModal(type, userId, statusValue) {
        const u = usersById[userId];
        const modal = confirmModals[type];
        if (!u || !modal) return;
        confirmTargetUserId = userId;
        confirmTargetStatusValue = statusValue || null;
        fillConfirmModal(type, u);

        if (type === 'status') {
            const label = statusLabels[statusValue] || statusValue;
            document.getElementById('umSetStatusTitle').textContent = 'Mark as ' + label;
            document.getElementById('umSetStatusCopy').textContent = 'Set this user\u2019s status to "' + label + '"?';
        }

        if (type === 'toggle-active') {
            const willDeactivate = !!u.is_active;
            document.getElementById('umDeactivateTitle').textContent = willDeactivate ? 'Deactivate User' : 'Reactivate User';
            document.getElementById('umDeactivateCopy').textContent = willDeactivate
                ? 'The account will be marked deactivated and hidden from active rosters. Continue for:'
                : 'The account will regain the ability to log in. Continue for:';
            document.getElementById('umDeactivateConfirmBtn').textContent = willDeactivate ? 'Deactivate User' : 'Reactivate User';
        }

        if (type === 'reset') {
            document.getElementById('umResetConfirmView').hidden = false;
            document.getElementById('umResetResultView').hidden = true;
            document.getElementById('umResetConfirmActions').hidden = false;
            document.getElementById('umResetDoneActions').hidden = true;
        }

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

    document.querySelectorAll('[data-confirm-action]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const type = btn.getAttribute('data-confirm-action');
            if (!confirmTargetUserId) return;
            const userId = confirmTargetUserId;

            if (type === 'reset') {
                btn.disabled = true;
                postAction({ action: 'reset_password', user_id: userId }).then(function (result) {
                    btn.disabled = false;
                    if (result.data && result.data.success) {
                        document.getElementById('umResetTempPassword').value = result.data.temp_password || '';
                        document.getElementById('umResetConfirmView').hidden = true;
                        document.getElementById('umResetResultView').hidden = false;
                        document.getElementById('umResetConfirmActions').hidden = true;
                        document.getElementById('umResetDoneActions').hidden = false;
                    } else {
                        closeConfirmModal(type);
                        showToast((result.data && result.data.error) || 'Could not reset password.', true);
                    }
                });
                return;
            }

            let params = null;
            if (type === 'status') {
                params = { action: 'set_status', user_id: userId, status: confirmTargetStatusValue };
            } else if (type === 'toggle-active') {
                params = { action: 'toggle_active', user_id: userId };
            } else if (type === 'archive') {
                params = { action: 'archive_user', user_id: userId };
            } else if (type === 'restore') {
                params = { action: 'restore_user', user_id: userId };
            }
            if (!params) return;

            closeConfirmModal(type);
            postAction(params).then(function (result) {
                if (result.data && result.data.success) {
                    queueToastAndReload(result.data.message || 'Done.', type === 'archive');
                } else {
                    showToast((result.data && result.data.error) || 'Something went wrong. Please try again.', true);
                }
            });
        });
    });

    const resetCopyBtn = document.getElementById('umResetCopyBtn');
    if (resetCopyBtn) {
        resetCopyBtn.addEventListener('click', function () {
            const input = document.getElementById('umResetTempPassword');
            input.select();
            navigator.clipboard && navigator.clipboard.writeText(input.value).then(function () {
                showToast('Temporary password copied.');
            }).catch(function () {
                showToast('Could not copy automatically — please copy manually.', true);
            });
        });
    }

    const resetDoneBtn = document.getElementById('umResetDoneBtn');
    if (resetDoneBtn) {
        resetDoneBtn.addEventListener('click', function () {
            closeConfirmModal('reset');
            queueToastAndReload('Password reset.');
        });
    }

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
        else if (action === 'reset') openConfirmModal('reset', userId);
        else if (action === 'status') openConfirmModal('status', userId, actionBtn.getAttribute('data-status-value'));
        else if (action === 'toggle-active') openConfirmModal('toggle-active', userId);
        else if (action === 'archive') openConfirmModal('archive', userId);
        else if (action === 'restore') openConfirmModal('restore', userId);
    });

    // Drawer's own Edit / Reset Password buttons act on whichever user is
    // currently open in the drawer.
    const drawerEditBtn = document.getElementById('umDrawerEditBtn');
    const drawerResetBtn = document.getElementById('umDrawerResetBtn');
    if (drawerEditBtn) drawerEditBtn.addEventListener('click', function () {
        if (currentDrawerUserId) openUserModal('edit', currentDrawerUserId);
    });
    if (drawerResetBtn) drawerResetBtn.addEventListener('click', function () {
        if (currentDrawerUserId) openConfirmModal('reset', currentDrawerUserId);
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
        const roleMap = { patients: 'patient', doctors: 'doctor', pharmacists: 'pharmacist', administrators: 'admin', staff: 'staff' };

        if (tab) {
            const role = roleMap[tab] || tab;
            const matchingTab = document.querySelector('.um-tabs .tab-btn[data-role="' + role + '"]');
            if (matchingTab) matchingTab.click();
        }

        if (action === 'add') {
            openUserModal('add', null);
            if (tab) {
                const role = roleMap[tab] || tab;
                const roleSelect = userForm.querySelector('[name="role"]');
                if (roleSelect && role) roleSelect.value = role;
                syncStaffTypeVisibility();
            }
        }
    })();

    // Initial paint.
    applyFilters();
})();