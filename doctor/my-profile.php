<?php
// doctor/profile.php

require_once '../includes/auth_guard.php';
require_role('doctor');
require_once '../config/db.php';

// Logged-in doctor's info comes from the session (set during login).
$doctorFirstName = $_SESSION['first_name'];
$doctorLastName  = $_SESSION['last_name'];
$doctorFullName  = "Dr. " . $doctorFirstName . " " . $doctorLastName;
$doctorInitial   = strtoupper(substr($doctorFirstName, 0, 1));
$doctorSpecialty = $_SESSION['specialty'] ?? 'Internal Medicine';

$today = date("F j, Y");

// NOTE: Contact details, license info, duty schedule, and account
// activity below remain placeholder/sample data for UI development
// only. Replace with real queries once the doctors table and
// account-settings backend are implemented.

$doctorInfo = [
    "email"          => strtolower($doctorFirstName . "." . $doctorLastName) . "@omcdh.gov.ph",
    "phone"          => "+63 917 123 4567",
    "license_no"     => "PRC-0123456",
    "department"     => "Internal Medicine",
    "employee_id"    => "OMCDH-2021-0142",
    "years_service"  => "5 years",
    "member_since"   => "June 2021",
    "last_login"     => "Today, 8:12 AM",
];

$dutySchedule = [];
$doctor_id = $_SESSION['user_id'];

// The doctor's department, used in the Assignment column below.
$stmt = $conn->prepare("
    SELECT d.department_name
    FROM users u
    JOIN departments d ON u.department_id = d.department_id
    WHERE u.user_id = ?
");
$stmt->bind_param("i", $doctor_id);
$stmt->execute();
$doctor_department = $stmt->get_result()->fetch_assoc()['department_name'] ?? '—';
$stmt->close();

// Real on-duty dates from duty_schedule, same 7-day rolling window
// book-appointment.php uses to build the patient-facing slot grid.
// start_time/end_time are optional per-day overrides (NULL = full
// clinic day) for a future admin duty-editor.
$duty_hours_by_date = [];
$stmt = $conn->prepare("
    SELECT duty_date, start_time, end_time FROM duty_schedule
    WHERE doctor_id = ? AND duty_date >= CURDATE() AND duty_date < DATE_ADD(CURDATE(), INTERVAL 7 DAY)
");
$stmt->bind_param("i", $doctor_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $duty_hours_by_date[$row['duty_date']] = [
        'start' => $row['start_time'],
        'end'   => $row['end_time'],
    ];
}
$stmt->close();

// duty_schedule marks whole days as on-duty by default (start/end_time
// NULL), showing the standard clinic hours used everywhere else in the
// system: 8AM-4PM, minus the 11AM-1PM lunch break. If start_time/
// end_time ARE set for a date, that custom range is shown instead.
// Sundays are skipped since the clinic is closed, same as the booking flow.
$duty_schedule_start = new DateTime('today');
for ($i = 0; $i < 7; $i++) {
    $day = (clone $duty_schedule_start)->modify("+$i days");
    if ($day->format('N') == 7) continue;

    $date_str = $day->format('Y-m-d');
    $is_on_duty = isset($duty_hours_by_date[$date_str]);

    $time_label = "Off Duty";
    if ($is_on_duty) {
        $custom_start = $duty_hours_by_date[$date_str]['start'];
        $custom_end   = $duty_hours_by_date[$date_str]['end'];

        if ($custom_start && $custom_end) {
            $time_label = date('g:i A', strtotime($custom_start)) . " – " . date('g:i A', strtotime($custom_end));
        } else {
            $time_label = "8:00 AM – 4:00 PM (Lunch 11:00 AM–1:00 PM)";
        }
    }

    $dutySchedule[] = [
        "day"        => $day->format('l, M j'),
        "time"       => $time_label,
        "department" => $is_on_duty ? $doctor_department : "—",
    ];
}

$accountActivity = [
    [
        "text" => "Profile information updated",
        "time" => "3 days ago",
    ],
    [
        "text" => "Password changed",
        "time" => "2 weeks ago",
    ],
    [
        "text" => "Logged in from a new device",
        "time" => "1 month ago",
    ],
];

$current_page = 'profile';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - GabayMed</title>
    <link rel="stylesheet" href="../assets/css/doctor-dashboard.css">
</head>

<body>

    <div class="app-shell">

        <?php include 'includes/sidebar.php'; ?>

        <!-- Main content -->
        <main class="main-content">

            <header class="page-header">
                <div>
                    <h1>My Profile</h1>
                    <p class="page-subtitle">Manage your personal information and account settings.</p>
                </div>
                <div class="header-actions">
                    <span class="header-date"><?php echo htmlspecialchars($today); ?></span>

                </div>
            </header>

            <!-- Content grid -->
            <section class="content-grid">

                <!-- Left column -->
                <div>

                    <!-- Profile summary -->
                    <div class="card patient-summary-card">
                        <div class="summary-header-row">
                            <div class="patient-header">
                                <div class="patient-avatar-large"><?php echo htmlspecialchars($doctorInitial); ?></div>
                                <div>
                                    <div class="patient-name"><?php echo htmlspecialchars($doctorFullName); ?></div>
                                    <div class="patient-meta"><?php echo htmlspecialchars($doctorSpecialty); ?></div>
                                    <div class="patient-submeta">Employee ID: <?php echo htmlspecialchars($doctorInfo['employee_id']); ?></div>
                                </div>
                            </div>
                            <span class="summary-value status-pill status-active">Active</span>
                        </div>
                        <div class="summary-divider"></div>
                        <div class="summary-grid">
                            <div class="summary-item">
                                <span class="summary-label">Department</span>
                                <span class="summary-value"><?php echo htmlspecialchars($doctorInfo['department']); ?></span>
                            </div>
                            <div class="summary-item">
                                <span class="summary-label">License No.</span>
                                <span class="summary-value"><?php echo htmlspecialchars($doctorInfo['license_no']); ?></span>
                            </div>
                            <div class="summary-item">
                                <span class="summary-label">Years of Service</span>
                                <span class="summary-value"><?php echo htmlspecialchars($doctorInfo['years_service']); ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Edit profile -->
                    <div class="card patient-info-card">
                        <div class="card-header">
                            <h2>Edit Profile</h2>
                        </div>
                        <form class="consultation-form">
                            <div class="patient-info-grid">
                                <div class="form-group">
                                    <label class="form-label" for="profileFirstName">First Name</label>
                                    <input type="text" class="form-input" id="profileFirstName" name="first_name" value="<?php echo htmlspecialchars($doctorFirstName); ?>">
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="profileLastName">Last Name</label>
                                    <input type="text" class="form-input" id="profileLastName" name="last_name" value="<?php echo htmlspecialchars($doctorLastName); ?>">
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="profileSpecialty">Specialty</label>
                                    <select class="form-input" id="profileSpecialty" name="specialty">
                                        <option <?php echo $doctorSpecialty === 'Internal Medicine' ? 'selected' : ''; ?>>Internal Medicine</option>
                                        <option <?php echo $doctorSpecialty === 'Cardiology' ? 'selected' : ''; ?>>Cardiology</option>
                                        <option <?php echo $doctorSpecialty === 'Endocrinology' ? 'selected' : ''; ?>>Endocrinology</option>
                                        <option <?php echo $doctorSpecialty === 'Pediatrics' ? 'selected' : ''; ?>>Pediatrics</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="profilePhone">Phone Number</label>
                                    <input type="text" class="form-input" id="profilePhone" name="phone" value="<?php echo htmlspecialchars($doctorInfo['phone']); ?>">
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="profileEmail">Email Address</label>
                                <input type="text" class="form-input" id="profileEmail" name="email" value="<?php echo htmlspecialchars($doctorInfo['email']); ?>">
                                <span class="form-hint">Used for appointment and confinement notifications.</span>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="profileBio">About / Bio</label>
                                <textarea class="form-textarea" id="profileBio" name="bio" rows="3" placeholder="A short professional summary shown to hospital staff...">Board-certified physician specializing in <?php echo htmlspecialchars(strtolower($doctorSpecialty)); ?> with a focus on preventive and community-based care at OMCDH.</textarea>
                            </div>
                            <div class="table-actions">
                                <button type="submit" class="btn btn-primary">Save Changes</button>
                                <button type="reset" class="btn btn-secondary">Cancel</button>
                            </div>
                        </form>
                    </div>

                    <!-- Duty schedule -->
                    <div class="card">
                        <div class="card-header">
                            <h2>Duty Schedule</h2>
                            <span class="card-subtitle">Next 7 days</span>
                        </div>
                        <div class="table-wrap">
                            <table class="records-table">
                                <thead>
                                    <tr>
                                        <th>Day</th>
                                        <th>Duty Hours</th>
                                        <th>Assignment</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($dutySchedule as $slot): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($slot['day']); ?></td>
                                            <td><?php echo htmlspecialchars($slot['time']); ?></td>
                                            <td><?php echo htmlspecialchars($slot['department']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                </div>

                <!-- Right column -->
                <div class="side-column">

                    <!-- Change password -->
                    <div class="card">
                        <div class="card-header">
                            <h2>Change Password</h2>
                        </div>
                        <form class="consultation-form">
                            <div class="form-group">
                                <label class="form-label" for="currentPassword">Current Password</label>
                                <input type="password" class="form-input" id="currentPassword" name="current_password" placeholder="Enter current password">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="newPassword">New Password</label>
                                <input type="password" class="form-input" id="newPassword" name="new_password" placeholder="Enter new password">
                                <span class="form-hint">At least 8 characters, with a number and symbol.</span>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="confirmPassword">Confirm New Password</label>
                                <input type="password" class="form-input" id="confirmPassword" name="confirm_password" placeholder="Re-enter new password">
                            </div>
                            <button type="submit" class="btn btn-primary">Update Password</button>
                        </form>
                    </div>

                    <!-- Account info -->
                    <div class="card">
                        <div class="card-header">
                            <h2>Account Info</h2>
                        </div>
                        <div class="patient-info-grid">
                            <div class="info-item">
                                <span class="info-label">Member Since</span>
                                <span class="info-value"><?php echo htmlspecialchars($doctorInfo['member_since']); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Last Login</span>
                                <span class="info-value"><?php echo htmlspecialchars($doctorInfo['last_login']); ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Recent account activity -->
                    <div class="card activity-card">
                        <div class="card-header">
                            <h2>Recent Account Activity</h2>
                        </div>
                        <ul class="activity-timeline">
                            <?php foreach ($accountActivity as $item): ?>
                                <li class="activity-item">
                                    <span class="activity-dot"></span>
                                    <div class="activity-body">
                                        <p><?php echo htmlspecialchars($item['text']); ?></p>
                                        <span class="activity-time"><?php echo htmlspecialchars($item['time']); ?></span>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>

                </div>
            </section>

            <footer class="page-footer">
                <p>&copy; <?php echo date("Y"); ?> GabayMed Hospital Management System</p>
            </footer>

        </main>
    </div>

    <script src="../assets/js/doctor-dashboard.js"></script>
</body>

</html>