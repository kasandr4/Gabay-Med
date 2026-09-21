<?php
// patient/includes/department_matcher.php
//
// Implements spec 1.5 Step 2 — Department Recommendation.
// Simple, free keyword-matching: no AI/API calls needed, as confirmed
// acceptable in the spec ("nice-to-have" rationale, not required).
//
// HOW IT WORKS:
// Each keyword has a WEIGHT, not just a yes/no match. Strong identity
// signals (e.g. "child", "pregnant", "wound") score much higher than
// generic symptom words (e.g. "fever", "cough") that could apply to
// almost anyone. This way "my child has a fever" correctly routes to
// Pediatrics instead of Internal Medicine, even though "fever" alone
// would normally point to Internal Medicine.
//
// Weight scale:
//   3 = strong identity signal (who the patient is / clearly department-specific)
//   2 = moderately specific symptom/condition
//   1 = generic symptom that could apply broadly
//
// MATCHING (fixed 2026-08-02): word-boundary regex, not raw substring
// search. Substring search let short keywords match INSIDE unrelated
// words — 'kid' inside "kidney", 'cut' inside "acute", 'ari' inside
// Tagalog words like "kasarian", 'tb' inside "outbreak" — all of which
// are everyday phrasings, not edge cases. See recommend_department()
// below for the actual match.

function get_department_keywords()
{
    // NOTE: keys here MUST match department_name in the departments table exactly.
    // Format: 'keyword' => weight
    // Includes English + common Tagalog/Taglish phrasings, since real patients
    // often describe symptoms in Filipino rather than pure English.
    // NOTE (2026-09-16): Surgery removed entirely - the department was
    // soft-deleted (departments.is_active = 0, see
    // 023_deactivate_surgery_and_drop_waitlist.sql) since patient booking
    // is check-ups only now. No symptom should route there anymore.
    return [
        'OB-GYN' => [
            'pregnant' => 3,
            'pregnancy' => 3,
            'buntis' => 3,
            'prenatal' => 3,
            'miscarriage' => 3,
            'nakunan' => 3,
            'labor' => 3,
            'manganganak' => 3,
            'contraction' => 3,
            'postpartum' => 3,
            'delivery' => 3,
            'panganganak' => 3,
            'cesarean' => 3,
            'gynecology' => 3,
            'pagbubuntis' => 3,
            'menstrual' => 2,
            'menstruation' => 2,
            'regla' => 2,
            'irregular period' => 2,
            'pap smear' => 2,
            'cervical' => 2,
            'ovarian' => 2,
            'family planning' => 2,
            'pampaplano ng pamilya' => 2,
            'breast lump' => 2,
            'period' => 1,
            'vaginal' => 1,
            'ari' => 1,
            'ovary' => 1,
            'uterus' => 1,
            'matris' => 1,
            'discharge' => 1,
            'pagdurugo sa ari' => 2,
        ],
        'Internal Medicine' => [
            'diabetes' => 2,
            'diyabetis' => 2,
            'hypertension' => 2,
            'high blood pressure' => 2,
            'altapresyon' => 2,
            'blood sugar' => 2,
            'checkup' => 2,
            'check-up' => 2,
            'pacheck-up' => 2,
            'thyroid' => 2,
            'fever' => 1,
            'lagnat' => 1,
            'cough' => 1,
            'ubo' => 1,
            'cold' => 1,
            'sipon' => 1,
            'flu' => 1,
            'trangkaso' => 1,
            'headache' => 1,
            'sakit ng ulo' => 1,
            'masakit ang ulo' => 1,
            'body pain' => 1,
            'body ache' => 1,
            'masakit ang katawan' => 1,
            'panghihina' => 1,
            'dizziness' => 1,
            'nahihilo' => 1,
            'hilo' => 1,
            'fatigue' => 1,
            'pagod' => 1,
            'weakness' => 1,
            'mahina' => 1,
            'stomach pain' => 1,
            'abdominal pain' => 1,
            'masakit ang tiyan' => 1,
            'sakit ng tiyan' => 1,
            'diarrhea' => 1,
            'pagtatae' => 1,
            'lbm' => 1,
            'vomiting' => 1,
            'suka' => 1,
            'nasusuka' => 1,
            'nausea' => 1,
            'general illness' => 1,
            'masama ang pakiramdam' => 1,
            'chest pain' => 1,
            'masakit ang dibdib' => 1,
            'urinary' => 1,
            'kidney' => 1,
            'bato' => 1,
        ],
        'Pediatrics' => [
            'my child' => 3,
            'my son' => 3,
            'my daughter' => 3,
            'my baby' => 3,
            'anak ko' => 3,
            'pediatric' => 3,
            'vaccination' => 3,
            'vaccine' => 3,
            'bakuna' => 3,
            'immunization' => 3,
            'newborn' => 3,
            'infant' => 3,
            'child' => 3,
            'baby' => 3,
            'sanggol' => 3,
            'bata' => 3,
            'toddler' => 3,
            'measles' => 2,
            'tigdas' => 2,
            'chickenpox' => 2,
            'bulutong' => 2,
            'kid' => 2,
            'growth' => 1,
            'development' => 1,
            'paglaki' => 1,
        ],
        'Pulmonology' => [
            'asthma' => 3,
            'hika' => 3,
            'tuberculosis' => 3,
            'tibi' => 3,
            'pneumonia' => 3,
            'bronchitis' => 3,
            'copd' => 3,
            'difficulty breathing' => 2,
            'shortness of breath' => 2,
            'hirap huminga' => 2,
            'hingal' => 2,
            'chronic cough' => 2,
            'matagal na ubo' => 2,
            'chest tightness' => 2,
            'tb' => 2,
            'wheezing' => 1,
            'lung' => 1,
            'baga' => 1,
            'phlegm' => 1,
            'plema' => 1,
            'sputum' => 1,
            'breathless' => 1,
        ],
    ];
}

/**
 * Analyzes a symptom description and returns the recommended department name
 * along with a short rationale (matched keywords), or a safe default.
 *
 * @param string $symptom_text The patient's free-text symptom description
 * @return array ['department' => string, 'rationale' => string|null, 'matched_count' => int]
 */
function recommend_department($symptom_text)
{
    $text = strtolower($symptom_text);
    $keywords_by_department = get_department_keywords();

    $scores = [];
    $matched_keywords_by_department = [];

    foreach ($keywords_by_department as $department => $weighted_keywords) {
        $total_score = 0;
        $matches = [];

        foreach ($weighted_keywords as $keyword => $weight) {
            // Word-boundary match, not raw substring search. Plain strpos()
            // let short keywords silently match INSIDE unrelated words —
            // 'kid' inside ki[kid]ney (falsely boosting Pediatrics over
            // Internal Medicine's own 'kidney' entry), 'cut' inside a[cut]e,
            // 'ari' inside Tagalog words like kas[ari]an, 'tb' inside
            // ou[tb]reak. All four are real, common everyday phrasings
            // ("kidney pain", "acute pain"), not edge cases.
            if (preg_match('/\b' . preg_quote($keyword, '/') . '\b/', $text)) {
                $matches[] = $keyword;
                $total_score += $weight;
            }
        }

        $scores[$department] = $total_score;
        $matched_keywords_by_department[$department] = $matches;
    }

    // Bonus signal: detect explicit young-child ages like "5 year old",
    // "3 years old", "8 months old" (and Tagalog: "5 taon", "8 buwan") —
    // these are strong Pediatrics signals regardless of sentence phrasing.
    if (
        preg_match('/\b([0-9]|1[0-2])\s*(year|years|yr|yrs)[\s-]*old\b/', $text)
        || preg_match('/\b([0-9]|1[0-1])\s*(month|months)[\s-]*old\b/', $text)
        || preg_match('/\b([0-9]|1[0-2])\s*(taon|taong gulang)\b/', $text)
        || preg_match('/\b([0-9]|1[0-1])\s*(buwan|buwang gulang)\b/', $text)
    ) {
        $scores['Pediatrics'] += 3;
        $matched_keywords_by_department['Pediatrics'][] = 'young age mentioned';
    }

    // Find the department with the highest weighted score
    $best_department = array_key_first($scores);
    $best_score = $scores[$best_department];

    foreach ($scores as $department => $score) {
        if ($score > $best_score) {
            $best_score = $score;
            $best_department = $department;
        }
    }

    // No keywords matched at all -> do NOT silently default to any
    // specific department (previously this defaulted to Internal
    // Medicine, which made it look like the system was recommending —
    // or worse, diagnosing — a department/treatment path with zero
    // actual evidence from the description). Callers must handle
    // department === null by asking the patient to add detail (patient
    // booking flow already does this) or by requiring staff to pick a
    // department manually (walk-in flow).
    if ($best_score === 0) {
        return [
            'department' => null,
            'rationale' => 'No specific department could be matched from this description.',
            'matched_count' => 0,
        ];
    }

    $matched = $matched_keywords_by_department[$best_department];
    $rationale = "Matched: " . implode(', ', array_slice($matched, 0, 3));

    return [
        'department' => $best_department,
        'rationale' => $rationale,
        'matched_count' => $best_score,
    ];
}
