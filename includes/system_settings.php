<?php
// includes/system_settings.php
//
// Read/write access to the system_settings table (see
// 020_create_system_settings.sql). Lets admin-tunable policy numbers
// (no-show strikes, lockout attempts, expiry thresholds, etc.) live in
// the database instead of hardcoded PHP constants, without changing how
// any call site reads them: get_setting_int('no_show_strike_limit', 3)
// is a drop-in replacement for the old NO_SHOW_STRIKE_LIMIT constant,
// falling back to that same default if the row is ever missing.
//
// Cached per-request (static array) so a page that reads the same
// setting from several functions (e.g. no_show_policy.php calling it
// from both is_eligible_for_no_show() and record_no_show()) only hits
// the DB once.

/**
 * Raw string value for a setting key, or $default if the row doesn't
 * exist. Prefer get_setting_int()/get_setting_decimal() at call sites —
 * this is the low-level fetch the typed helpers build on.
 */
function get_setting(mysqli $conn, string $key, ?string $default = null): ?string
{
    static $cache = [];

    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
    $stmt->bind_param("s", $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $cache[$key] = $row ? $row['setting_value'] : $default;
}

/**
 * Integer-typed convenience wrapper — covers every current setting
 * (grace minutes, strike limits, day counts, percentages).
 */
function get_setting_int(mysqli $conn, string $key, int $default): int
{
    $value = get_setting($conn, $key, (string) $default);
    return (int) $value;
}

/**
 * Decimal-typed convenience wrapper, for any future setting that isn't
 * a whole number (none of the current ones need it, but the table's
 * value_type column already anticipates it).
 */
function get_setting_decimal(mysqli $conn, string $key, float $default): float
{
    $value = get_setting($conn, $key, (string) $default);
    return (float) $value;
}

/**
 * Every row in system_settings, grouped by category, in the order
 * admin/system-settings.php should render them (category insertion
 * order, then each category's rows by setting_key). Used ONLY by the
 * settings page itself — call sites elsewhere in the app should keep
 * using get_setting_int() for a single value, not this.
 *
 * @return array<string, array> category => list of setting rows
 */
function get_all_settings_grouped(mysqli $conn): array
{
    $result = $conn->query("SELECT * FROM system_settings ORDER BY category, label");
    $grouped = [];
    while ($row = $result->fetch_assoc()) {
        $grouped[$row['category']][] = $row;
    }
    return $grouped;
}

/**
 * Admin-facing bulk update. $values is [setting_key => new value string],
 * typically straight from $_POST. Only keys that already exist in the
 * table are touched — this never inserts a new setting from user input.
 * Each row's min_value/max_value (if set) clamps the incoming value so a
 * stray typo (e.g. "0" attempts before lockout) can't brick the app;
 * out-of-range values are clamped rather than rejected outright, and the
 * clamped keys are returned so the caller can flash a heads-up.
 *
 * @return array{updated:string[], clamped:string[]} which keys changed,
 *         and which of those were clamped into range
 */
function update_settings(mysqli $conn, array $values, int $adminId): array
{
    $updated = [];
    $clamped = [];

    $stmt = $conn->prepare(
        "SELECT setting_key, value_type, min_value, max_value FROM system_settings WHERE setting_key = ?"
    );
    $updateStmt = $conn->prepare(
        "UPDATE system_settings
         SET setting_value = ?, updated_by = ?, updated_at = NOW()
         WHERE setting_key = ?"
    );

    foreach ($values as $key => $rawValue) {
        $stmt->bind_param("s", $key);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        if (!$row) {
            continue; // not a real setting key — ignore rather than insert blindly
        }

        $value = trim((string) $rawValue);

        if ($row['value_type'] === 'int' || $row['value_type'] === 'decimal') {
            $numeric = $row['value_type'] === 'int' ? (int) $value : (float) $value;

            if ($row['min_value'] !== null && $numeric < (int) $row['min_value']) {
                $numeric = (int) $row['min_value'];
                $clamped[] = $key;
            }
            if ($row['max_value'] !== null && $numeric > (int) $row['max_value']) {
                $numeric = (int) $row['max_value'];
                $clamped[] = $key;
            }

            $value = (string) $numeric;
        }

        $updateStmt->bind_param("sis", $value, $adminId, $key);
        $updateStmt->execute();

        if ($updateStmt->affected_rows > 0) {
            $updated[] = $key;
        }
    }

    $stmt->close();
    $updateStmt->close();

    return ['updated' => $updated, 'clamped' => array_unique($clamped)];
}
