<?php
// categories.php — the ONE list of assistance categories used by every form,
// save script, and the records page. Edit this list to add/rename a category.

if (!defined('ASSISTANCE_CATEGORIES')) {
    define('ASSISTANCE_CATEGORIES', [
        "Medicine",
        "Hospital Bill",
        "Laboratory / Diagnostic Tests",
        "Dialysis",
        "Chemotherapy",
        "Surgery",
        "Emergency / Accident Treatment",
        "Medical Checkup / Consultation",
        "Others",
    ]);
}

/**
 * Returns the official spelling of a category (case-insensitive match),
 * or null if the value is not in the list.
 */
function canonicalAssistanceCategory(string $value): ?string {
    $value = trim($value);
    foreach (ASSISTANCE_CATEGORIES as $cat) {
        if (strcasecmp($cat, $value) === 0) return $cat;
    }
    return null;
}

/**
 * Maps an OLD (assistance_type, medical_cause) pair to one new category.
 * Same rules as migrate_merge_categories.sql: a specific assistance type wins;
 * a generic one (Medical Assistance, Cash, Guarantee Letter...) falls back to the cause.
 */
function mapLegacyToCategory(?string $assistanceType, ?string $medicalCause): string {
    $direct = canonicalAssistanceCategory((string)$assistanceType);
    if ($direct !== null) return $direct;

    $t = strtolower(trim((string)$assistanceType));
    if ($t !== '') {
        $typeRules = [
            'chemotherapy'  => 'Chemotherapy',
            'dialysis'      => 'Dialysis',
            'laborator'     => 'Laboratory / Diagnostic Tests',
            'surgery'       => 'Surgery',
            'medicine'      => 'Medicine',
            'hospital bill' => 'Hospital Bill',
        ];
        foreach ($typeRules as $needle => $cat) {
            if (strpos($t, $needle) !== false) return $cat;
        }
    }

    $causeMap = [
        'chemotherapy'       => 'Chemotherapy',
        'surgery'            => 'Surgery',
        'dialysis'           => 'Dialysis',
        'laboratory tests'   => 'Laboratory / Diagnostic Tests',
        'hospitalization'    => 'Hospital Bill',
        'emergency treatment'=> 'Emergency / Accident Treatment',
        'accident injury'    => 'Emergency / Accident Treatment',
        'medical checkup'    => 'Medical Checkup / Consultation',
    ];
    $c = strtolower(trim((string)$medicalCause));
    if (isset($causeMap[$c])) return $causeMap[$c];

    return canonicalAssistanceCategory((string)$medicalCause) ?? 'Others';
}