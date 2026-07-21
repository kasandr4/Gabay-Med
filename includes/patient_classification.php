<?php
// includes/patient_classification.php
//
// OPD vs. Resident (inpatient) is NOT a separate stored field. It's
// derived from users.status, which already has exactly one writer for
// 'confined' (doctor/confine-patient-process.php) and exactly one writer
// that clears it (doctor/confinement-discharge-process.php). A patient is
// a Resident for as long as they have an active, undischarged
// confinement — the moment they're discharged, users.status moves off
// 'confined' and they're OPD again automatically. No new column, no
// separate "classification" to keep in sync — this just labels state
// that already exists.
//
// This is deliberately a different question than the existing
// Active/Confined/Blocked status pill shown elsewhere: that pill answers
// "what's this account's standing" (could also be Blocked or Deceased).
// This helper answers "what kind of care is this patient currently
// receiving" — OPD or Resident — which is why both can appear side by
// side rather than one replacing the other.

/**
 * @param string $userStatus The users.status value ('active','blocked','confined','deceased').
 * @return array{label: string, class: string} Display label and badge CSS class.
 */
function patient_classification(string $userStatus): array
{
    if ($userStatus === 'confined') {
        return ['label' => 'Resident (Inpatient)', 'class' => 'classification-resident'];
    }
    return ['label' => 'OPD (Outpatient)', 'class' => 'classification-opd'];
}
