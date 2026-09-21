<?php
// patient/includes/symptom_catalog.php
//
// Powers the PATIENT-facing checkbox symptom picker on
// patient/book-appointment.php (2026-09-16 rework: free-text symptom
// description + manual department/doctor picking replaced with a fixed
// checklist that routes straight to a department, then an
// auto-assigned doctor - see match_department_from_symptoms() below).
//
// DELIBERATELY SEPARATE from department_matcher.php: that file's
// free-text, weighted-keyword recommend_department() is still used
// as-is by staff/walk-in.php (front-desk staff typing in what a walk-in
// patient tells them - that flow was NOT part of this change and still
// needs free text, since staff aren't limited to a fixed checklist the
// way a patient-facing form is). Keeping the two matchers in separate
// files means editing one can never accidentally break the other.
//
// Surgery has no entries here (or in department_matcher.php - see that
// file's own 2026-09-16 note) since patient booking is check-ups only.

/**
 * The fixed list of symptoms a patient can check on the booking form.
 * Each key => ['department' => ..., 'label' => ...].
 *
 * Keys are what's actually submitted (symptoms[]) - never trust the
 * label text itself, only the key, exactly like every other
 * server-validated form field in this app. Labels are English with a
 * Filipino/Tagalog translation in parentheses, matching how real OMCDH
 * patients describe things (see department_matcher.php's own header
 * comment on why - same reasoning applies here).
 *
 * Rendered on the page grouped under patient-friendly category headers
 * (see patient/book-appointment.php's $symptom_groups) rather than the
 * exact department name - a patient doesn't need to know "OB-GYN" is
 * the department, just that "Pregnancy & Women's Health" is the right
 * group for what they're checking.
 */
function get_symptom_catalog(): array
{
    return [
        // ---- OB-GYN ----
        'ob_pregnant' => ['department' => 'OB-GYN', 'label' => 'Pregnant / prenatal check-up (Buntis / prenatal checkup)'],
        'ob_family_planning' => ['department' => 'OB-GYN', 'label' => 'Family planning consultation (Pagpaplano ng pamilya)'],
        'ob_menstrual' => ['department' => 'OB-GYN', 'label' => 'Menstrual or period problems (Problema sa regla)'],
        'ob_postpartum' => ['department' => 'OB-GYN', 'label' => 'Postpartum / after-delivery care (Pangangalaga pagkatapos manganak)'],
        'ob_other' => ['department' => 'OB-GYN', 'label' => "Other women's health concern (Iba pang alalahanin sa kalusugan ng babae)"],

        // ---- Pediatrics ----
        'ped_fever_cough' => ['department' => 'Pediatrics', 'label' => 'Child has fever, cough, or colds (Lagnat, ubo, o sipon ng bata)'],
        'ped_vaccination' => ['department' => 'Pediatrics', 'label' => 'Vaccination / immunization (Bakuna)'],
        'ped_growth_checkup' => ['department' => 'Pediatrics', 'label' => 'Child wellness / growth check-up (Regular checkup ng bata)'],
        'ped_newborn' => ['department' => 'Pediatrics', 'label' => 'Newborn or infant concern (Alalahanin sa sanggol)'],

        // ---- Internal Medicine ----
        'im_fever_cough' => ['department' => 'Internal Medicine', 'label' => 'Fever, cough, or colds (Lagnat, ubo, o sipon)'],
        'im_headache_bodypain' => ['department' => 'Internal Medicine', 'label' => 'Headache or body pain (Sakit ng ulo o katawan)'],
        'im_stomach' => ['department' => 'Internal Medicine', 'label' => 'Stomach pain, diarrhea, or vomiting (Sakit ng tiyan, pagtatae, o pagsusuka)'],
        'im_chronic_condition' => ['department' => 'Internal Medicine', 'label' => 'High blood pressure / diabetes follow-up (Altapresyon / diyabetis)'],
        'im_general_checkup' => ['department' => 'Internal Medicine', 'label' => 'General check-up / other concern (Pangkalahatang checkup)'],

        // ---- Pulmonology ----
        'pu_breathing_difficulty' => ['department' => 'Pulmonology', 'label' => 'Difficulty breathing or asthma attack (Hirap huminga o atake ng hika)'],
        'pu_chronic_cough' => ['department' => 'Pulmonology', 'label' => 'Long-lasting cough, 2 weeks or more (Matagal na ubo)'],
        'pu_chest_tightness' => ['department' => 'Pulmonology', 'label' => 'Chest tightness or wheezing (Paninikip ng dibdib)'],
    ];
}

/**
 * Re-derives a department from a set of submitted symptom keys, from
 * scratch, server-side - the ONLY code path patient/book-appointment.php
 * trusts for "which department is this." The old wizard's hidden
 * confirmed_department/department_override inputs are gone entirely
 * (there's no manual override left to submit) - the department is
 * always recomputed from symptoms[] alone, both on the AJAX match call
 * and again on the real confirm_booking submit, same "never trust the
 * client" rule as everything else in this booking flow.
 *
 * Tie-break rule: if the checked symptoms point at more than one
 * department with an equal count, this is NOT resolved automatically -
 * a patient checking one OB-GYN box and one Pediatrics box, for
 * example, genuinely needs two different kinds of care, and this app's
 * schema only supports one department per appointment (appointments.
 * department_id is a single column). Returns an error asking the
 * patient to narrow it down rather than silently guessing which one
 * they meant.
 *
 * @param string[] $submittedKeys
 * @return array{success:bool, error?:string, department?:string, matched_labels?:string[]}
 */
function match_department_from_symptoms(array $submittedKeys): array
{
    $catalog = get_symptom_catalog();

    // Only keys that actually exist in the catalog count - anything else
    // (tampered/unknown value) is silently dropped rather than trusted.
    $validKeys = array_values(array_unique(array_intersect(array_map('strval', $submittedKeys), array_keys($catalog))));

    if (empty($validKeys)) {
        return ['success' => false, 'error' => 'Please check at least one symptom that applies to you.'];
    }

    $countsByDept = [];
    $labelsByDept = [];
    foreach ($validKeys as $key) {
        $dept = $catalog[$key]['department'];
        $countsByDept[$dept] = ($countsByDept[$dept] ?? 0) + 1;
        $labelsByDept[$dept][] = $catalog[$key]['label'];
    }

    arsort($countsByDept);
    $topCount = reset($countsByDept);
    $topDepartments = array_keys(array_filter($countsByDept, fn($c) => $c === $topCount));

    if (count($topDepartments) > 1) {
        return [
            'success' => false,
            'error' => "It looks like you selected symptoms for more than one type of care. Please check symptoms for just your main concern for this visit — for anything else, please visit the front desk directly.",
        ];
    }

    $department = $topDepartments[0];

    return [
        'success' => true,
        'department' => $department,
        'matched_labels' => $labelsByDept[$department],
    ];
}
