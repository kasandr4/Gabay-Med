<?php
// admin/includes/user-management-data.php
// Real data layer for the User Management module. Replaces the earlier
// static $placeholderUsers array - the shape of each returned row is kept
// deliberately close to that placeholder (full_name, initials, role_label,
// status_label, etc. all still exist) so user-management.php and
// print-user-profile.php needed field-level, not structural, changes.
//
// ---- Status model -------------------------------------------------------
// Real `users` rows carry four separate signals that this file collapses
// into one display status (reusing the same status-pill classes the old
// mock UI already had, plus new ones for confined/deceased/archived):
//   - archived_at (NULL / datetime)  set -> always "Archived", checked
//                                     first. Archived users are excluded
//                                     from the normal list entirely (see
//                                     um_fetch_users()) - this only
//                                     matters when viewing the Archived
//                                     list itself.
//   - is_active (0/1)                is_active=0 -> "Deactivated"
//   - account_status (guest/active)  'guest' -> "Pending" (not yet
//                                     activated - e.g. a walk-in guest
//                                     patient account created before their
//                                     Google activation flow completes)
//   - status (active/blocked/confined/deceased) -> shown directly
//                                     otherwise
// Priority: Archived > Deactivated > Pending > Blocked/Confined/Deceased > Active.
//
// "Delete User" does NOT run a real DELETE - it sets archived_at instead
// (see admin/user-management-actions.php's archive_user/restore_user and
// user_archive_migration.sql). Hospital records shouldn't disappear.
//
// Note: real `users` has no `username` column (login is by phone_number)
// and no login-history table, so those two fields from the old mock data
// are gone rather than faked.

$roleLabels = [
    'patient'    => 'Patient',
    'doctor'     => 'Doctor',
    'pharmacist' => 'Pharmacist',
    'admin'      => 'Administrator',
    'staff'      => 'Hospital Staff',
    'capitol'    => 'Provincial Capitol',
];

$statusLabels = [
    'active'      => 'Active',
    'pending'     => 'Pending',
    'blocked'     => 'Blocked',
    'confined'    => 'Confined',
    'deceased'    => 'Deceased',
    'deactivated' => 'Deactivated',
    'archived'    => 'Archived',
];

/**
 * Collapses archived_at + is_active + account_status + status into the
 * one display status key described above.
 */
function um_resolve_status(array $row): string
{
    if (!empty($row['archived_at'])) {
        return 'archived';
    }
    if (!(int) $row['is_active']) {
        return 'deactivated';
    }
    if ($row['account_status'] === 'guest') {
        return 'pending';
    }
    if (in_array($row['status'], ['blocked', 'confined', 'deceased'], true)) {
        return $row['status'];
    }
    return 'active';
}

/**
 * Adds the same presentation fields (full_name, initials, role_label,
 * status_key, status_label, department_name fallback) to a raw users row.
 */
function um_decorate_user(array $row): array
{
    global $roleLabels, $statusLabels;

    $row['full_name'] = trim($row['first_name'] . ' ' . $row['last_name']);
    $row['initials'] = strtoupper(substr($row['first_name'], 0, 1) . substr($row['last_name'], 0, 1));
    $row['role_label'] = $roleLabels[$row['role']] ?? ucfirst($row['role']);
    // Staff is one role but two unrelated jobs (013_staff_subtype.sql) -
    // fold the distinction into role_label itself so every existing
    // display call site (table, drawer, print profile) picks it up for
    // free instead of needing its own staff_type-aware branch.
    if ($row['role'] === 'staff' && !empty($row['staff_type'])) {
        $staffTypeLabels = ['front_desk' => 'Front Desk', 'inventory' => 'Inventory Counting'];
        $row['role_label'] .= ' · ' . ($staffTypeLabels[$row['staff_type']] ?? ucfirst($row['staff_type']));
    }
    $row['department_name'] = $row['department_name'] ?: 'No Department';

    $statusKey = um_resolve_status($row);
    $row['status_key'] = $statusKey;
    $row['status_label'] = $statusLabels[$statusKey] ?? ucfirst($statusKey);

    return $row;
}

const UM_USER_SELECT_FIELDS = "u.user_id AS id, u.first_name, u.last_name, u.email,
        u.phone_number AS phone, u.role, u.staff_type, u.department_id, d.department_name,
        u.status, u.account_status, u.is_active, u.archived_at, u.sex, u.birthdate, u.address,
        u.created_at AS date_registered";

/**
 * Users, newest first, decorated for display. Keyed by user_id so both
 * the table and the umUsers JS lookup object can share one shape.
 * By default excludes archived users (archived_at IS NOT NULL) - pass
 * $archivedOnly=true to fetch just the archived list instead (used by
 * the "Archived" view).
 */
function um_fetch_users(mysqli $conn, bool $archivedOnly = false): array
{
    $condition = $archivedOnly ? "u.archived_at IS NOT NULL" : "u.archived_at IS NULL";
    $result = $conn->query(
        "SELECT " . UM_USER_SELECT_FIELDS . "
         FROM users u
         LEFT JOIN departments d ON d.department_id = u.department_id
         WHERE {$condition}
         ORDER BY u.created_at DESC"
    );
    $users = [];
    while ($row = $result->fetch_assoc()) {
        $users[(int) $row['id']] = um_decorate_user($row);
    }
    return $users;
}

/**
 * One user by id, decorated for display, or null if not found. Includes
 * archived users (the print-profile page and restore action both need
 * to be able to look one up regardless of archived state).
 */
function um_fetch_user(mysqli $conn, int $userId): ?array
{
    $stmt = $conn->prepare(
        "SELECT " . UM_USER_SELECT_FIELDS . "
         FROM users u
         LEFT JOIN departments d ON d.department_id = u.department_id
         WHERE u.user_id = ?"
    );
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? um_decorate_user($row) : null;
}
