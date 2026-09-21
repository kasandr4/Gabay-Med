<?php
// admin/system-settings.php
// Module — System Settings.
//
// Lets admin tune hospital policy numbers (no-show strikes, login
// lockout attempts, expiry thresholds, reorder buffer, booking window)
// without a code change. Backed by the system_settings table
// (020_create_system_settings.sql) and includes/system_settings.php's
// get_setting_int()/update_settings() helpers — every other file in the
// app that used to read a hardcoded constant now reads through those
// instead, so a value changed here takes effect immediately, no
// redeploy needed.
//
// Same plain form-POST-to-self + $_SESSION flash pattern as
// staff/check-in.php. Each category card below is its OWN <form> with
// its own Save button — saving one category doesn't touch the others'
// unsaved edits, since update_settings() only ever writes the keys
// actually present in that form's POST body.

require_once '../includes/auth_guard.php';
require_role('admin');
require_once '../config/db.php';
require_once '../includes/csrf.php';
require_once '../includes/audit_log.php';
require_once '../includes/system_settings.php';

$current_page = 'system-settings';
$today = date("F j, Y");

$flash = $_SESSION['settings_flash'] ?? null;
unset($_SESSION['settings_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf('system-settings.php', 'settings_flash');

    // Which category card was submitted — used only to scroll back to
    // it and to word the flash message; update_settings() itself
    // doesn't need to know, since $submitted already only contains that
    // one card's fields.
    $submittedCategory = $_POST['category'] ?? '';

    // Every field on the submitted form is named settings[<setting_key>]
    // — pull the whole sub-array rather than allowlisting field names
    // here, since update_settings() already only touches keys that
    // exist in the table (see its "not a real setting key — ignore"
    // guard).
    $submitted = $_POST['settings'] ?? [];
    $adminId = $_SESSION['user_id'];

    $result = update_settings($conn, $submitted, $adminId);

    if (!empty($result['updated'])) {
        write_audit_log(
            $conn,
            $adminId,
            'admin',
            'system_settings_updated',
            'system_settings',
            'Updated: ' . implode(', ', $result['updated'])
        );
    }

    $message = empty($result['updated'])
        ? 'No changes were made.'
        : count($result['updated']) . ' setting' . (count($result['updated']) === 1 ? '' : 's') . ' updated.';

    if (!empty($result['clamped'])) {
        $message .= ' Note: ' . implode(', ', $result['clamped']) . ' had a value outside the allowed range and was adjusted to fit.';
    }

    $_SESSION['settings_flash'] = [
        'type' => empty($result['clamped']) ? 'success' : 'warning',
        'message' => $message,
        'category' => $submittedCategory,
    ];
    $redirect = 'system-settings.php' . ($submittedCategory !== '' ? '#cat-' . urlencode($submittedCategory) : '');
    header('Location: ' . $redirect);
    exit;
}

$groups = get_all_settings_grouped($conn);

$categoryLabels = [
    'no_show'    => 'No-Show & Cancellation',
    'security'   => 'Login Security',
    'inventory'  => 'Pharmacy & Inventory',
    'scheduling' => 'Scheduling & Booking',
];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Settings - GabayMed</title>
    <link rel="stylesheet" href="../assets/css/doctor-dashboard.css">
    <link rel="stylesheet" href="../assets/css/admin-dashboard.css">
    <style>
        .settings-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 14px;
            padding: 4px 20px 20px;
        }

        .settings-field {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .settings-field label {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-primary, #1a1a1a);
        }

        .settings-field input[type="number"] {
            width: 140px;
            padding: 8px 10px;
            border: 1px solid #d9dde3;
            border-radius: 8px;
            font-size: 14px;
        }

        .settings-field .settings-help {
            font-size: 12px;
            color: #6b7280;
            max-width: 420px;
        }

        .settings-field .settings-bounds {
            font-size: 11px;
            color: #9aa0a8;
        }

        .settings-card-footer {
            display: flex;
            justify-content: flex-end;
            padding: 6px 20px 18px;
        }
    </style>
</head>

<body>

    <div class="app-shell">

        <?php include 'includes/sidebar.php'; ?>

        <main class="main-content">

            <header class="page-header">
                <div>
                    <h1>System Settings</h1>
                    <p class="page-subtitle">Hospital policy numbers used across the app — change them here instead of editing code.</p>
                </div>
                <div class="header-actions">
                    <span class="header-date"><?php echo htmlspecialchars($today); ?></span>
                </div>
            </header>

            <?php if ($flash): ?>
                <?php
                $flashCategoryLabel = !empty($flash['category']) ? ($categoryLabels[$flash['category']] ?? ucfirst($flash['category'])) : null;
                ?>
                <section id="<?php echo $flashCategoryLabel ? 'cat-' . htmlspecialchars($flash['category']) . '-flash' : 'settings-flash'; ?>" class="card" style="border-left: 4px solid <?php echo $flash['type'] === 'error' ? 'var(--red)' : ($flash['type'] === 'warning' ? '#C98A00' : 'var(--teal)'); ?>; margin-bottom: 20px;">
                    <p style="margin: 0; padding: 14px 20px; font-size: 14px; color: <?php echo $flash['type'] === 'error' ? 'var(--red)' : 'var(--text-primary)'; ?>;">
                        <?php echo $flashCategoryLabel ? '<strong>' . htmlspecialchars($flashCategoryLabel) . ':</strong> ' : ''; ?><?php echo htmlspecialchars($flash['message']); ?>
                    </p>
                </section>
            <?php endif; ?>

            <?php foreach ($groups as $category => $settings): ?>
                <section id="cat-<?php echo htmlspecialchars($category); ?>" class="card" style="margin-bottom: 20px;">
                    <form method="POST" action="system-settings.php">
                        <?= csrf_field() ?>
                        <input type="hidden" name="category" value="<?php echo htmlspecialchars($category); ?>">

                        <div class="card-header">
                            <div>
                                <h2><?php echo htmlspecialchars($categoryLabels[$category] ?? ucfirst($category)); ?></h2>
                            </div>
                        </div>

                        <div class="settings-grid">
                            <?php foreach ($settings as $s): ?>
                                <div class="settings-field">
                                    <label for="setting-<?php echo htmlspecialchars($s['setting_key']); ?>">
                                        <?php echo htmlspecialchars($s['label']); ?>
                                    </label>
                                    <input
                                        type="number"
                                        id="setting-<?php echo htmlspecialchars($s['setting_key']); ?>"
                                        name="settings[<?php echo htmlspecialchars($s['setting_key']); ?>]"
                                        value="<?php echo htmlspecialchars($s['setting_value']); ?>"
                                        <?php if ($s['min_value'] !== null): ?>min="<?php echo (int) $s['min_value']; ?>" <?php endif; ?>
                                        <?php if ($s['max_value'] !== null): ?>max="<?php echo (int) $s['max_value']; ?>" <?php endif; ?>
                                        step="<?php echo $s['value_type'] === 'decimal' ? '0.01' : '1'; ?>">
                                    <?php if ($s['description']): ?>
                                        <span class="settings-help"><?php echo htmlspecialchars($s['description']); ?></span>
                                    <?php endif; ?>
                                    <?php if ($s['min_value'] !== null || $s['max_value'] !== null): ?>
                                        <span class="settings-bounds">
                                            Allowed range: <?php echo $s['min_value'] !== null ? (int) $s['min_value'] : '—'; ?>
                                            – <?php echo $s['max_value'] !== null ? (int) $s['max_value'] : '—'; ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="settings-card-footer">
                            <button type="submit" class="btn btn-primary">
                                Save <?php echo htmlspecialchars($categoryLabels[$category] ?? ucfirst($category)); ?>
                            </button>
                        </div>
                    </form>
                </section>
            <?php endforeach; ?>

        </main>
    </div>

</body>

</html>