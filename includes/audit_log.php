<?php
// Shared, best-effort audit writer. Audit failures must not undo a successful workflow action.
//
// $patientId (2026-09-06): optional - set it whenever the logged action
// concerns one specific patient (prescribing, dispensing). Left NULL for
// role/module-only actions (inventory counts, catalog edits, reports)
// where there's no single patient to attribute it to. Appended as the
// last parameter (default null) so every existing call site keeps
// working unchanged.
function write_audit_log(mysqli $conn, int $userId, string $userRole, string $action, string $module, string $details = '', ?int $patientId = null): void
{
    try {
        $stmt = $conn->prepare(
            "INSERT INTO audit_log (user_id, user_role, patient_id, action, module, details)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('isisss', $userId, $userRole, $patientId, $action, $module, $details);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        // Logging must never make an otherwise successful business action fail.
    }
}
