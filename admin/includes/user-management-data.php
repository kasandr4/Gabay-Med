<?php
// admin/includes/user-management-data.php
// Shared placeholder dataset for the User Management module (UI ONLY).
//
// This stands in for a future `users` table query. Every field name below
// deliberately matches (or closely mirrors) real `users` columns already
// used elsewhere in the codebase (first_name, last_name, email, role,
// is_active, created_at, last_login) so wiring this to $conn later is a
// drop-in swap, not a redesign. Included by both user-management.php
// (the table/cards) and print-user-profile.php (the PDF-style preview),
// so both pages always agree on the same placeholder record for a given
// user_id — the same pattern hospital-census.php uses for its drawer data.
//
// TODO(backend): replace with
//   SELECT user_id, first_name, last_name, email, phone, role,
//          department, account_status, is_active, created_at, last_login
//   FROM users
//   ORDER BY created_at DESC
// "role_label", "department", "avatar_color" etc. below are presentation
// helpers that don't exist as columns yet; derive them the same way this
// file does once real rows are available.

$roleLabels = [
    'patient'       => 'Patient',
    'doctor'        => 'Doctor',
    'pharmacist'    => 'Pharmacist',
    'admin'         => 'Administrator',
    'staff'         => 'Hospital Staff',
];

$statusLabels = [
    'active'        => 'Active',
    'inactive'      => 'Inactive',
    'blocked'       => 'Blocked',
    'pending'       => 'Pending',
    'deactivated'   => 'Deactivated',
];

$placeholderUsers = [
    [
        'id' => 1001, 'first_name' => 'Juan', 'last_name' => 'Dela Cruz',
        'email' => 'juan.delacruz@gabaymed.local', 'phone' => '0917 123 4521',
        'role' => 'patient', 'department' => 'Outpatient',
        'status' => 'active', 'gender' => 'Male', 'birthdate' => '1990-04-12',
        'address' => 'Blk 4 Lot 7, Cavite City, Cavite',
        'date_registered' => '2026-01-14', 'last_login' => '2026-07-15 08:42',
        'username' => 'jdelacruz',
        'activity' => [
            ['text' => 'Logged in from Chrome on Windows', 'time' => 'Today, 8:42 AM'],
            ['text' => 'Booked appointment with Dr. Ramon Santos', 'time' => 'Jul 12, 2026'],
            ['text' => 'Updated contact number', 'time' => 'Jul 3, 2026'],
        ],
    ],
    [
        'id' => 1002, 'first_name' => 'Angela', 'last_name' => 'Mercado',
        'email' => 'angela.mercado@gabaymed.local', 'phone' => '0917 552 9012',
        'role' => 'patient', 'department' => 'Outpatient',
        'status' => 'pending', 'gender' => 'Female', 'birthdate' => '1996-11-02',
        'address' => 'Purok 3, Bacoor, Cavite',
        'date_registered' => '2026-07-10', 'last_login' => '—',
        'username' => 'amercado',
        'activity' => [
            ['text' => 'Registered via Google account activation', 'time' => 'Jul 10, 2026'],
            ['text' => 'Email verification pending', 'time' => 'Jul 10, 2026'],
        ],
    ],
    [
        'id' => 1003, 'first_name' => 'Pedro', 'last_name' => 'Reyes',
        'email' => 'pedro.reyes@gabaymed.local', 'phone' => '0918 224 7761',
        'role' => 'patient', 'department' => 'Surgery',
        'status' => 'inactive', 'gender' => 'Male', 'birthdate' => '1978-02-25',
        'address' => 'Sampaloc, Manila',
        'date_registered' => '2025-09-02', 'last_login' => '2026-04-18 14:05',
        'username' => 'preyes',
        'activity' => [
            ['text' => 'Discharged after post-op monitoring', 'time' => 'Apr 18, 2026'],
            ['text' => 'No activity since discharge', 'time' => '—'],
        ],
    ],
    [
        'id' => 1004, 'first_name' => 'Maria', 'last_name' => 'Santos',
        'email' => 'maria.santos@gabaymed.local', 'phone' => '0919 883 4410',
        'role' => 'patient', 'department' => 'Internal Medicine',
        'status' => 'blocked', 'gender' => 'Female', 'birthdate' => '1985-06-30',
        'address' => 'Imus, Cavite',
        'date_registered' => '2025-11-20', 'last_login' => '2026-06-02 09:15',
        'username' => 'msantos',
        'activity' => [
            ['text' => 'Account blocked — repeated missed appointments', 'time' => 'Jun 5, 2026'],
            ['text' => 'Booked and no-showed follow-up', 'time' => 'Jun 2, 2026'],
        ],
    ],
    [
        'id' => 2001, 'first_name' => 'Ramon', 'last_name' => 'Santos',
        'email' => 'ramon.santos@gabaymed.local', 'phone' => '0920 441 2290',
        'role' => 'doctor', 'department' => 'Internal Medicine',
        'status' => 'active', 'gender' => 'Male', 'birthdate' => '1979-03-18',
        'address' => 'Las Piñas City',
        'date_registered' => '2024-02-08', 'last_login' => '2026-07-15 07:58',
        'username' => 'dr.santos',
        'activity' => [
            ['text' => 'Completed consultation for Juan Dela Cruz', 'time' => 'Today, 9:10 AM'],
            ['text' => 'Updated weekly schedule', 'time' => 'Jul 13, 2026'],
        ],
    ],
    [
        'id' => 2002, 'first_name' => 'Liza', 'last_name' => 'Fernandez',
        'email' => 'liza.fernandez@gabaymed.local', 'phone' => '0921 774 3308',
        'role' => 'doctor', 'department' => 'Surgery',
        'status' => 'active', 'gender' => 'Female', 'birthdate' => '1983-09-05',
        'address' => 'Parañaque City',
        'date_registered' => '2024-05-19', 'last_login' => '2026-07-14 16:30',
        'username' => 'dr.fernandez',
        'activity' => [
            ['text' => 'Discharged patient Pedro Reyes', 'time' => 'Apr 18, 2026'],
            ['text' => 'Logged in from Safari on macOS', 'time' => 'Jul 14, 2026'],
        ],
    ],
    [
        'id' => 2003, 'first_name' => 'Carlo', 'last_name' => 'Villanueva',
        'email' => 'carlo.villanueva@gabaymed.local', 'phone' => '0922 118 6654',
        'role' => 'doctor', 'department' => 'Pediatrics',
        'status' => 'pending', 'gender' => 'Male', 'birthdate' => '1991-12-11',
        'address' => 'Bacoor, Cavite',
        'date_registered' => '2026-07-09', 'last_login' => '—',
        'username' => 'dr.villanueva',
        'activity' => [
            ['text' => 'Account created — awaiting credential verification', 'time' => 'Jul 9, 2026'],
        ],
    ],
    [
        'id' => 2004, 'first_name' => 'Grace', 'last_name' => 'Ibarra',
        'email' => 'grace.ibarra@gabaymed.local', 'phone' => '0923 665 1198',
        'role' => 'doctor', 'department' => 'Emergency',
        'status' => 'deactivated', 'gender' => 'Female', 'birthdate' => '1975-01-27',
        'address' => 'Cavite City',
        'date_registered' => '2023-08-14', 'last_login' => '2026-02-11 10:20',
        'username' => 'dr.ibarra',
        'activity' => [
            ['text' => 'Account deactivated — on extended leave', 'time' => 'Feb 15, 2026'],
        ],
    ],
    [
        'id' => 3001, 'first_name' => 'Rosario', 'last_name' => 'Padilla',
        'email' => 'rosario.padilla@gabaymed.local', 'phone' => '0924 302 7745',
        'role' => 'pharmacist', 'department' => 'Pharmacy',
        'status' => 'active', 'gender' => 'Female', 'birthdate' => '1988-07-22',
        'address' => 'Dasmariñas, Cavite',
        'date_registered' => '2024-11-03', 'last_login' => '2026-07-15 06:50',
        'username' => 'rpadilla',
        'activity' => [
            ['text' => 'Approved purchase request for Amoxicillin 500mg', 'time' => 'Yesterday, 3:20 PM'],
            ['text' => 'Updated inventory count for Paracetamol', 'time' => 'Jul 13, 2026'],
        ],
    ],
    [
        'id' => 3002, 'first_name' => 'Noel', 'last_name' => 'Bautista',
        'email' => 'noel.bautista@gabaymed.local', 'phone' => '0925 220 5583',
        'role' => 'pharmacist', 'department' => 'Pharmacy',
        'status' => 'inactive', 'gender' => 'Male', 'birthdate' => '1993-05-16',
        'address' => 'Kawit, Cavite',
        'date_registered' => '2025-01-27', 'last_login' => '2026-05-04 11:12',
        'username' => 'nbautista',
        'activity' => [
            ['text' => 'No login activity in the last 60 days', 'time' => '—'],
        ],
    ],
    [
        'id' => 4001, 'first_name' => 'Erika', 'last_name' => 'Mateo',
        'email' => 'erika.mateo@gabaymed.local', 'phone' => '0926 890 1147',
        'role' => 'admin', 'department' => 'Administration',
        'status' => 'active', 'gender' => 'Female', 'birthdate' => '1987-10-09',
        'address' => 'Parañaque City',
        'date_registered' => '2023-06-01', 'last_login' => '2026-07-15 08:01',
        'username' => 'emateo',
        'activity' => [
            ['text' => 'Created staff account for Grace Tolentino', 'time' => 'Jul 11, 2026'],
            ['text' => 'Approved reconciliation batch REC-2026-0714-018', 'time' => 'Jul 14, 2026'],
        ],
    ],
    [
        'id' => 4002, 'first_name' => 'Admin', 'last_name' => 'User',
        'email' => 'admin@gabaymed.local', 'phone' => '0927 001 0000',
        'role' => 'admin', 'department' => 'Administration',
        'status' => 'active', 'gender' => 'Male', 'birthdate' => '1982-01-01',
        'address' => 'GabayMed Hospital, Main Office',
        'date_registered' => '2022-01-01', 'last_login' => '2026-07-15 08:30',
        'username' => 'admin',
        'activity' => [
            ['text' => 'System configuration reviewed', 'time' => 'Jul 15, 2026'],
        ],
    ],
    [
        'id' => 5001, 'first_name' => 'Grace', 'last_name' => 'Tolentino',
        'email' => 'grace.tolentino@gabaymed.local', 'phone' => '0928 337 2201',
        'role' => 'staff', 'department' => 'Front Desk',
        'status' => 'active', 'gender' => 'Female', 'birthdate' => '1998-03-14',
        'address' => 'Bacoor, Cavite',
        'date_registered' => '2026-07-11', 'last_login' => '2026-07-15 07:40',
        'username' => 'gtolentino',
        'activity' => [
            ['text' => 'Checked in 6 patients for Today\'s Queue', 'time' => 'Today, 7:40 AM'],
        ],
    ],
    [
        'id' => 5002, 'first_name' => 'Ramon', 'last_name' => 'Cruz',
        'email' => 'ramon.cruz@gabaymed.local', 'phone' => '0929 664 8832',
        'role' => 'staff', 'department' => 'Records',
        'status' => 'pending', 'gender' => 'Male', 'birthdate' => '2000-08-19',
        'address' => 'Imus, Cavite',
        'date_registered' => '2026-07-14', 'last_login' => '—',
        'username' => 'rcruz',
        'activity' => [
            ['text' => 'Account created — awaiting first login', 'time' => 'Jul 14, 2026'],
        ],
    ],
    [
        'id' => 5003, 'first_name' => 'Bea', 'last_name' => 'Lozano',
        'email' => 'bea.lozano@gabaymed.local', 'phone' => '0930 774 5561',
        'role' => 'staff', 'department' => 'Billing',
        'status' => 'blocked', 'gender' => 'Female', 'birthdate' => '1994-12-02',
        'address' => 'Cavite City',
        'date_registered' => '2025-03-17', 'last_login' => '2026-06-28 13:44',
        'username' => 'blozano',
        'activity' => [
            ['text' => 'Account blocked — pending HR review', 'time' => 'Jun 29, 2026'],
        ],
    ],
    [
        'id' => 5004, 'first_name' => 'Tomas', 'last_name' => 'Aguilar',
        'email' => 'tomas.aguilar@gabaymed.local', 'phone' => '0931 220 9987',
        'role' => 'staff', 'department' => 'Housekeeping',
        'status' => 'deactivated', 'gender' => 'Male', 'birthdate' => '1980-05-05',
        'address' => 'Rosario, Cavite',
        'date_registered' => '2023-02-20', 'last_login' => '2026-01-09 09:00',
        'username' => 'taguilar',
        'activity' => [
            ['text' => 'Account deactivated — resigned', 'time' => 'Jan 12, 2026'],
        ],
    ],
];

// Derive per-record display helpers (role_label, status_label, full_name,
// initials) once here so both consuming pages stay in sync.
foreach ($placeholderUsers as &$u) {
    $u['role_label'] = $roleLabels[$u['role']] ?? ucfirst($u['role']);
    $u['status_label'] = $statusLabels[$u['status']] ?? ucfirst($u['status']);
    $u['full_name'] = trim($u['first_name'] . ' ' . $u['last_name']);
    $u['initials'] = strtoupper(substr($u['first_name'], 0, 1) . substr($u['last_name'], 0, 1));
}
unset($u);

function um_find_user($users, $id)
{
    foreach ($users as $u) {
        if ((int) $u['id'] === (int) $id) return $u;
    }
    return null;
}
