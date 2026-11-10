<?php
ob_start();

date_default_timezone_set('Asia/Manila');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../db.php';

function e($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

if ($id <= 0) {
    exit('Invalid request.');
}

/* =====================================================
   CSRF TOKEN
===================================================== */
if (empty($_SESSION['record_profile_token'])) {
    $_SESSION['record_profile_token'] = bin2hex(random_bytes(32));
}

$token = $_SESSION['record_profile_token'];

/* =====================================================
   DATABASE COLUMN INFORMATION
===================================================== */
$columnInfo = [];

$result = $conn->query("SHOW FULL COLUMNS FROM `aics_sample_data`");

if (!$result) {
    exit('Unable to read database columns: ' . e($conn->error));
}

while ($column = $result->fetch_assoc()) {
    $columnInfo[$column['Field']] = $column;
}

$columns = array_keys($columnInfo);

$editableFields = [];

foreach ($columnInfo as $field => $info) {
    $extra = strtolower($info['Extra'] ?? '');

    if (
        $field === 'id' ||
        strpos($extra, 'auto_increment') !== false ||
        strpos($extra, 'generated') !== false
    ) {
        continue;
    }

    $editableFields[] = $field;
}

/* =====================================================
   HELPERS
===================================================== */
function hasField(string $field): bool {
    global $columnInfo;
    return isset($columnInfo[$field]);
}

function fieldValue(array $row, string $field, $default = '') {
    return $row[$field] ?? $default;
}

function fieldLabel(string $field): string {
    $labels = [
        'application_code' => 'Application Reference Code',
        'id_number' => 'Beneficiary ID Number',
        'program_type' => 'Program Type',
        'client_nature' => 'Client Nature',
        'intake_venue' => 'Intake Venue',
        'intake_mode' => 'Intake Mode',
        'lname' => 'Apelyido / Last Name',
        'fname' => 'Unang Pangalan / First Name',
        'mname' => 'Gitnang Pangalan / Middle Name',
        'ext' => 'Ext (Jr./Sr./III)',
        'birth_date' => 'Kapanganakan / Date of Birth',
        'sex' => 'Kasarian / Sex',
        'civil_status' => 'Katayuang Sibil / Civil Status',
        'cp_number' => 'Telepono / Contact Number',
        'contact_number' => 'Contact Number',
        'occupation' => 'Trabaho / Occupation',
        'street' => 'House No. / Street / Purok',
        'barangay' => 'Barangay',
        'city' => 'Lungsod / City',
        'municipality' => 'Municipality',
        'province' => 'Province',
        'region' => 'Rehiyon / Region',
        'postal_code' => 'Postal Code',
        'assistance_type' => 'Uri ng Tulong / Type of Assistance',
        'client_category' => 'Kategorya ng Kliyente / Client Category',
        'client_subcategory' => 'Client Subcategory',
        'medical_cause' => 'Sub-Category and Medical Cause',
        'problem_presented' => 'Problem Presented',
        'social_worker_assessment' => "Social Worker's Assessment",
        'assessment' => 'Assessment',
        'remarks' => 'Remarks',
        'family_composition' => 'Family Composition',
        'status' => 'Record Status',
        'request_date' => 'Date of Request',
        'submitted_at' => 'Date Submitted',
        'created_at' => 'Date Created'
    ];

    return $labels[$field] ?? ucwords(str_replace('_', ' ', $field));
}

function displayDateValue($value, array $info): string {
    if ($value === null || $value === '') {
        return '—';
    }

    $type = strtolower($info['Type'] ?? '');

    if (
        preg_match('/^(datetime|timestamp)\b/', $type) &&
        strtotime((string)$value)
    ) {
        return date('F d, Y g:i A', strtotime((string)$value));
    }

    if (
        preg_match('/^date\b/', $type) &&
        $value !== '0000-00-00' &&
        strtotime((string)$value)
    ) {
        return date('F d, Y', strtotime((string)$value));
    }

    return (string)$value;
}

function inputTypeForField(string $field): string {
    global $columnInfo;

    $type = strtolower($columnInfo[$field]['Type'] ?? '');

    if (preg_match('/^date\b/', $type)) {
        return 'date';
    }

    if (preg_match('/^(datetime|timestamp)\b/', $type)) {
        return 'datetime-local';
    }

    if (preg_match('/^(tinyint|smallint|mediumint|int|bigint|decimal|float|double)/', $type)) {
        return 'number';
    }

    return 'text';
}

function textField(string $field, string $label, array $row): void {
    global $columnInfo;

    if (!isset($columnInfo[$field])) {
        return;
    }

    $value = $row[$field] ?? '';
    $type = inputTypeForField($field);

    if ($type === 'datetime-local' && $value !== '') {
        $value = str_replace(' ', 'T', substr((string)$value, 0, 16));
    }

    if ($type === 'date' && $value === '0000-00-00') {
        $value = '';
    }

    echo '<div class="info-item">';
    echo '<label>' . e($label) . '</label>';
    echo '<div class="filled-value view-value">' .
         e(displayDateValue($row[$field] ?? '', $columnInfo[$field])) .
         '</div>';

    echo '<input class="edit-input" type="' . e($type) . '" name="' .
         e($field) . '" value="' . e($value) . '"' .
         ($type === 'number' ? ' step="any"' : '') . '>';

    echo '</div>';
}

function selectField(
    string $field,
    string $label,
    array $row,
    array $options
): void {
    global $columnInfo;

    if (!isset($columnInfo[$field])) {
        return;
    }

    $current = trim((string)($row[$field] ?? ''));

    if ($current !== '' && !in_array($current, $options, true)) {
        $options[] = $current;
    }

    echo '<div class="info-item">';
    echo '<label>' . e($label) . '</label>';
    echo '<div class="filled-value view-value">' .
         e($current !== '' ? $current : '—') .
         '</div>';

    echo '<select class="edit-input" name="' . e($field) . '">';
    echo '<option value="">Select ' . e($label) . '</option>';

    foreach ($options as $option) {
        echo '<option value="' . e($option) . '" ' .
             (strcasecmp($current, $option) === 0 ? 'selected' : '') .
             '>' . e($option) . '</option>';
    }

    echo '</select>';
    echo '</div>';
}

function longField(string $field, string $label, array $row): void {
    global $columnInfo;

    if (!isset($columnInfo[$field])) {
        return;
    }

    $value = $row[$field] ?? '';

    echo '<div class="info-item">';
    echo '<label>' . e($label) . '</label>';
    echo '<div class="filled-value multiline view-value">' .
         ($value !== '' ? nl2br(e($value)) : '—') .
         '</div>';
    echo '<textarea class="edit-input" name="' . e($field) . '">' .
         e($value) . '</textarea>';
    echo '</div>';
}

/* =====================================================
   LOAD RECORD
===================================================== */
$stmt = $conn->prepare("
    SELECT *
    FROM `aics_sample_data`
    WHERE `id` = ?
    LIMIT 1
");

$stmt->bind_param('i', $id);
$stmt->execute();

$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    exit('Beneficiary record not found.');
}

/* =====================================================
   SAVE CHANGES
===================================================== */
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_profile'])) {

    if (
        empty($_POST['csrf_token']) ||
        !hash_equals($token, $_POST['csrf_token'])
    ) {
        exit('Invalid security token. Please reload the page.');
    }

    $updates = [];
    $values = [];
    $types = '';
    $saveError = '';

    foreach ($editableFields as $field) {
        if (!array_key_exists($field, $_POST)) {
            continue;
        }

        $value = trim((string)$_POST[$field]);
        $info = $columnInfo[$field];
        $sqlType = strtolower($info['Type'] ?? '');

        $isTextColumn = preg_match(
            '/^(char|varchar|tinytext|text|mediumtext|longtext|enum|set)\b/',
            $sqlType
        );

        // Convert submitted text values to Title Case.
        if ($value !== '' && $isTextColumn) {
        $value = function_exists('mb_convert_case')
        ? mb_convert_case(trim($value), MB_CASE_TITLE, 'UTF-8')
        : ucwords(strtolower(trim($value)));
    }

        if ($value === '') {
            $value = null;
        }

        // Convert datetime-local to MySQL DATETIME.
        if (
            $value !== null &&
            preg_match('/^(datetime|timestamp)\b/', $sqlType)
        ) {
            $value = str_replace('T', ' ', $value);

            if (strlen($value) === 16) {
                $value .= ':00';
            }

            $date = DateTime::createFromFormat('Y-m-d H:i:s', $value);

            if (!$date || $date->format('Y-m-d H:i:s') !== $value) {
                $saveError = 'Invalid date/time value for ' . fieldLabel($field) . '.';
                break;
            }

            $value = $date->format('Y-m-d H:i:s');
        }

        // Validate DATE values.
        if ($value !== null && preg_match('/^date\b/', $sqlType)) {
            $date = DateTime::createFromFormat('!Y-m-d', $value);

            if (!$date || $date->format('Y-m-d') !== $value) {
                $saveError = 'Invalid date value for ' . fieldLabel($field) . '.';
                break;
            }
        }

        // Do not send NULL to a NOT NULL column.
        if ($value === null && ($info['Null'] ?? 'YES') === 'NO') {
            if (
                preg_match('/^(char|varchar|tinytext|text|mediumtext|longtext|enum|set)\b/', $sqlType)
            ) {
                $value = '';
            } else {
                // Keep the existing value for blank non-nullable numeric/date fields.
                continue;
            }
        }

        $safeField = str_replace('`', '``', $field);
        $updates[] = "`{$safeField}` = ?";
        $values[] = $value;

        if (preg_match('/^(tinyint|smallint|mediumint|int|bigint)/', $sqlType)) {
            $types .= 'i';
        } elseif (preg_match('/^(decimal|float|double)/', $sqlType)) {
            $types .= 'd';
        } else {
            $types .= 's';
        }
    }

    if ($saveError !== '') {
        $message = $saveError;
        $messageType = 'error';

    } elseif (!empty($updates)) {

        $sql = "
            UPDATE `aics_sample_data`
            SET " . implode(', ', $updates) . "
            WHERE `id` = ?
        ";

        $values[] = $id;
        $types .= 'i';

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            $message = 'Unable to prepare database update: ' . $conn->error;
            $messageType = 'error';
        } else {
            $bindParams = [$types];

            foreach ($values as $key => $value) {
                $bindParams[] = &$values[$key];
            }

            call_user_func_array([$stmt, 'bind_param'], $bindParams);

            if ($stmt->execute()) {
                $_SESSION['record_profile_flash'] = [
                    'message' => 'Beneficiary profile successfully updated.',
                    'type' => 'success'
                ];

                $stmt->close();

                header('Location: view_record_profile.php?id=' . $id);
                exit;
            }

            $message = 'Database update failed: ' . $stmt->error;
            $messageType = 'error';
            $stmt->close();
        }
    }
}

/* =====================================================
   FLASH MESSAGE
===================================================== */
if (isset($_SESSION['record_profile_flash'])) {
    $message = $_SESSION['record_profile_flash']['message'];
    $messageType = $_SESSION['record_profile_flash']['type'];
    unset($_SESSION['record_profile_flash']);
}

/* =====================================================
   RELOAD LATEST RECORD
===================================================== */
$stmt = $conn->prepare("
    SELECT *
    FROM `aics_sample_data`
    WHERE `id` = ?
    LIMIT 1
");

$stmt->bind_param('i', $id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    exit('Beneficiary record not found.');
}

/* =====================================================
   DISPLAY VALUES
===================================================== */
$fname = $row['fname'] ?? '';
$mname = $row['mname'] ?? '';
$lname = $row['lname'] ?? '';
$ext = $row['ext'] ?? '';

$fullname = strtoupper(trim(
    $fname . ' ' .
    ($mname !== '' ? $mname . ' ' : '') .
    $lname .
    ($ext !== '' ? ' ' . $ext : '')
));

$printed = date('F d, Y g:i A');

$reference = $row['application_code']
    ?? $row['id_number']
    ?? 'N/A';

$status = $row['status'] ?? 'Approved';

$statusClass = in_array(
    strtolower((string)$status),
    ['approved', 'claimed', 'completed'],
    true
) ? 'claimed' : '';

$dateField = null;

foreach (['request_date', 'submitted_at', 'created_at'] as $candidate) {
    if (
        isset($columnInfo[$candidate]) &&
        !empty($row[$candidate])
    ) {
        $dateField = $candidate;
        break;
    }
}

$submitted = $dateField
    ? displayDateValue($row[$dateField], $columnInfo[$dateField])
    : 'N/A';

$submittedRaw = $dateField ? $row[$dateField] : '';

$submittedTimestamp = $submittedRaw && strtotime((string)$submittedRaw)
    ? strtotime((string)$submittedRaw)
    : time();

/* =====================================================
   FAMILY COMPOSITION
===================================================== */
$family = [];

if (!empty($row['family_composition'])) {
    $decoded = json_decode((string)$row['family_composition'], true);

    if (is_array($decoded)) {
        // Accept either a list or an object containing a members list.
        if (isset($decoded['members']) && is_array($decoded['members'])) {
            $family = $decoded['members'];
        } elseif (array_is_list($decoded)) {
            $family = $decoded;
        }
    }
}

/* =====================================================
   TRACK FIELDS ALREADY SHOWN IN THE FORM
===================================================== */
$usedFields = ['id'];

function markFields(array $fields): void {
    global $usedFields;

    foreach ($fields as $field) {
        $usedFields[$field] = true;
    }
}

$classificationFields = [
    'program_type',
    'client_nature',
    'intake_venue',
    'intake_mode'
];

$partOneFields = [
    'lname', 'fname', 'mname', 'ext',
    'birth_date', 'sex', 'civil_status',
    'cp_number', 'occupation',
    'street', 'barangay', 'city', 'region'
];

$partTwoFields = [
    'assistance_type',
    'client_category',
    'medical_cause'
];

$specialFields = [
    'application_code',
    'request_date',
    'submitted_at',
    'created_at',
    'status',
    'family_composition',
    'problem_presented',
    'social_worker_assessment',
    'assessment',
    'remarks',
    'purpose',
    'purpose_of_assistance',
    'assistance_purpose',
    'amount',
    'assistance_amount',
    'fund_source',
    'source_of_fund'
];

$purposeFields = [];

for ($i = 1; $i <= 3; $i++) {
    foreach (['purpose_', 'amount_', 'fund_source_'] as $prefix) {
        $purposeFields[] = $prefix . $i;
    }
}

markFields($classificationFields);
markFields($partOneFields);
markFields($partTwoFields);
markFields($specialFields);
markFields($purposeFields);

/* Family data is displayed in the table. */
$otherFields = [];

foreach ($editableFields as $field) {
    if (!isset($usedFields[$field])) {
        $otherFields[] = $field;
    }
}

/* =====================================================
   AGE
===================================================== */
$age = '—';

if (
    !empty($row['birth_date']) &&
    $row['birth_date'] !== '0000-00-00'
) {
    try {
        $birth = new DateTime($row['birth_date']);
        $today = new DateTime('today');
        $age = $birth->diff($today)->y;
    } catch (Exception $exception) {
        $age = '—';
    }
}

/* =====================================================
   ASSISTANCE DROPDOWNS
===================================================== */
$types = [
    'Medical Assistance',
    'Financial Assistance',
    'Burial Assistance',
    'Transportation Assistance',
    'Educational Assistance',
    'Other Assistance'
];

$categories = [
    'Physical Disability',
    'Psychosocial Disability',
    'Rare Disease',
    'Speech Impairment',
    'Tuberculosis & Chronic Illness',
    'Other'
];

$subcategories = [
    'Hospitalization',
    'Medicine',
    'Laboratory',
    'Medical Procedure',
    'Dialysis',
    'Chemotherapy',
    'Other Medical Cause'
];

/* Preserve existing database values in dropdowns. */
foreach ([
    'assistance_type' => &$types,
    'client_category' => &$categories,
    'medical_cause' => &$subcategories
] as $field => &$options) {
    $current = trim((string)($row[$field] ?? ''));

    if ($current !== '' && !in_array($current, $options, true)) {
        $options[] = $current;
    }
}
unset($options);
?>
<!DOCTYPE html>
<html lang="fil">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>General Intake Sheet — <?= e($fullname) ?></title>

<link rel="icon" type="image/png"
      href="<?= defined('BASE_URL') ? BASE_URL : '../' ?>images/dswdlogo2.png">

<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap"
      rel="stylesheet">

<link rel="stylesheet"
      href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<style>
:root {
    --navy: #003893;
    --red: #ce1126;
    --gold: #c8a94a;
    --light: #f0f4ff;
    --border: #d1d9e6;
    --text: #1e293b;
    --muted: #64748b;
}

* {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

body {
    font-family: 'Inter', sans-serif;
    background: #e8ecf2;
    color: var(--text);
    padding: 24px;
}

.toolbar {
    max-width: 820px;
    margin: 0 auto 16px;
    display: flex;
    gap: 10px;
    align-items: center;
    flex-wrap: wrap;
}

.btn-toolbar {
    padding: 10px 20px;
    border-radius: 8px;
    border: none;
    font-family: 'Inter', sans-serif;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    text-decoration: none;
}

.btn-print { background: var(--navy); color: #fff; }
.btn-close { background: #fff; color: var(--muted); border: 1px solid var(--border); }
.btn-edit { background: #f59e0b; color: #fff; }
.btn-save { background: #10b981; color: #fff; display: none; }
.btn-cancel-edit { background: #64748b; color: #fff; display: none; }

.document {
    max-width: 820px;
    margin: 0 auto;
    background: #fff;
    border: 1.5px solid var(--border);
    box-shadow: 0 8px 30px rgba(0,0,0,.12);
}

.doc-header {
    background: var(--navy);
    color: #fff;
    padding: 18px 28px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
}

.logo-wrap {
    display: flex;
    align-items: center;
    gap: 14px;
}

.seal {
    width: 56px;
    height: 56px;
    background: #fff;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 26px;
    flex-shrink: 0;
}

.doc-header h1 {
    font-size: 15px;
    font-weight: 800;
    line-height: 1.2;
}

.doc-header p {
    font-size: 10px;
    opacity: .75;
    margin-top: 3px;
    letter-spacing: .5px;
}

.right-info {
    text-align: right;
    font-size: 11px;
    opacity: .85;
    line-height: 1.7;
}

.accent-bar {
    height: 5px;
    background: repeating-linear-gradient(
        90deg,
        var(--gold) 0,
        var(--gold) 20px,
        var(--red) 20px,
        var(--red) 40px
    );
}

/* REFERENCE CODE */
.code-banner {
    background: var(--light);
    border-bottom: 1.5px solid var(--border);
    padding: 14px 28px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 10px;
}

.code-value {
    font-family: 'Courier New', monospace;
    font-size: 20px;
    font-weight: 800;
    color: var(--navy);
    overflow-wrap: anywhere;
}

.status-pill {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 800;
    text-transform: uppercase;
    background: #fef9c3;
    color: #854d0e;
}

.status-pill.claimed {
    background: #dcfce7;
    color: #166534;
}

/* CLASSIFICATION HEADER */
.intake-class-grid {
    display: grid;
    grid-template-columns: 1.2fr 1fr 1.2fr 2fr;
    border-bottom: 2.5px solid #000;
    background: #fff;
    font-size: 11px;
    font-weight: 700;
}

.intake-class-grid > div {
    padding: 10px 14px;
    border-right: 1.5px solid #000;
    display: flex;
    flex-direction: column;
    justify-content: center;
    gap: 6px;
    min-width: 0;
}

.intake-class-grid > div:last-child {
    border-right: none;
}

.intake-row {
    display: flex;
    align-items: center;
    gap: 5px;
    min-height: 17px;
}

.check-box,
.circle-box {
    width: 13px;
    height: 13px;
    border: 1px solid #111;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    font-size: 10px;
    font-weight: 900;
    color: #000;
}

.circle-box {
    border-radius: 50%;
}

.check-item {
    display: inline-flex;
    align-items: center;
    gap: 5px;
}

.date-box-wrap {
    display: flex;
    justify-content: center;
    gap: 5px;
}

.date-box {
    border: 1px solid #111;
    min-height: 22px;
    min-width: 32px;
    padding: 4px;
    font-size: 10px;
    font-weight: 700;
    text-align: center;
}

/* SECTIONS */
.section-title {
    background: var(--navy);
    color: #fff;
    padding: 9px 28px;
    font-size: 11px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 1px;
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
}

.section-title.red { background: var(--red); }
.section-title.slate { background: #475569; }

.section-title span.sub {
    font-weight: 400;
    font-size: 10px;
    opacity: .8;
    font-style: italic;
    text-transform: none;
    letter-spacing: 0;
}

.info-body {
    padding: 20px 28px;
}

.info-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 16px 24px;
}

.info-grid.cols-2 {
    grid-template-columns: 1fr 1fr;
}

.info-grid.cols-4 {
    grid-template-columns: 2fr 1.5fr 1fr 1fr;
}

.info-grid.cols-full {
    grid-template-columns: 1fr;
}

.info-item {
    min-width: 0;
}

.info-item label {
    font-size: 9px;
    font-weight: 800;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: .8px;
    display: block;
    margin-bottom: 3px;
}

.info-item .filled-value {
    border-bottom: 1.5px solid var(--navy);
    min-height: 26px;
    padding-bottom: 4px;
    font-size: 13px;
    font-weight: 700;
    color: var(--text);
    text-transform: uppercase;
    overflow-wrap: anywhere;
    white-space: pre-wrap;
}

.info-item .filled-value.multiline {
    min-height: 65px;
    white-space: pre-wrap;
    text-transform: none;
    overflow-wrap: anywhere;
}

.edit-input {
    display: none;
    width: 100%;
    min-height: 30px;
    padding: 5px 7px;
    border: 1px solid var(--navy);
    border-radius: 4px;
    font-family: 'Inter', sans-serif;
    font-size: 12px;
    font-weight: 600;
    color: var(--text);
    background: #fff;
    text-transform: none;
}

textarea.edit-input {
    min-height: 90px;
    resize: vertical;
}

.edit-mode .edit-input {
    display: block;
}

.edit-mode .view-value {
    display: none !important;
}

.edit-mode .info-item .edit-input {
    margin-top: 4px;
}

.hr {
    border: 0;
    border-top: 1px solid #e2e8f0;
    margin: 0;
}

/* TABLES */
.family-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
}

.family-table th {
    background: #f8fafc;
    padding: 8px 12px;
    text-align: left;
    font-size: 9px;
    font-weight: 800;
    color: var(--muted);
    text-transform: uppercase;
    border-bottom: 1.5px solid var(--border);
    letter-spacing: .5px;
}

.family-table td {
    padding: 8px 12px;
    border-bottom: 1px solid #f1f5f9;
    height: 30px;
    color: var(--text);
    text-transform: uppercase;
    font-weight: 600;
}

.family-table tr:last-child td {
    border-bottom: none;
}

.table-edit-input {
    display: none;
    width: 100%;
    min-width: 60px;
    padding: 5px;
    border: 1px solid var(--navy);
    border-radius: 4px;
    font: inherit;
}

.edit-mode .table-edit-input {
    display: block;
}

.edit-mode .table-view-value {
    display: none;
}

/* SIGNATURE AND FOOTER */
.sig-area {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 40px;
    padding: 24px 28px;
    border-top: 1.5px solid var(--border);
}

.sig-box { text-align: center; }

.sig-line {
    border-bottom: 1.5px solid var(--text);
    margin-bottom: 6px;
    height: 40px;
}

.sig-label {
    font-size: 10px;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: .5px;
}

.doc-footer {
    background: var(--navy);
    padding: 10px 28px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 10px;
    color: rgba(255,255,255,.65);
    gap: 10px;
}

.doc-footer .gold {
    color: var(--gold);
    font-weight: 700;
}

.alert {
    max-width: 820px;
    margin: 0 auto 15px;
    padding: 13px 16px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 700;
}

.alert.success { background: #dcfce7; color: #166534; }
.alert.error { background: #fee2e2; color: #991b1b; }

@media print {
    * {
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }

    body {
        background: #fff;
        padding: 0;
    }

    .toolbar,
    .alert {
        display: none !important;
    }

    .edit-input,
    .table-edit-input {
        display: none !important;
    }

    .document {
        box-shadow: none;
        border: none;
        max-width: 100%;
    }

    @page {
        margin: 12mm 14mm;
        size: A4;
    }
}

@media(max-width: 650px) {
    body { padding: 10px; }

    .intake-class-grid {
        grid-template-columns: 1fr 1fr;
    }

    .intake-class-grid > div:nth-child(2) {
        border-right: none;
    }

    .intake-class-grid > div:nth-child(3),
    .intake-class-grid > div:nth-child(4) {
        border-top: 1px solid #000;
    }

    .info-grid,
    .info-grid.cols-2,
    .info-grid.cols-4 {
        grid-template-columns: 1fr 1fr;
    }

    .doc-header {
        flex-direction: column;
        align-items: flex-start;
    }

    .right-info { text-align: left; }

    .doc-footer {
        flex-direction: column;
        align-items: flex-start;
    }
}

@media(max-width: 400px) {
    .info-grid,
    .info-grid.cols-2,
    .info-grid.cols-4 {
        grid-template-columns: 1fr;
    }

    .sig-area {
        grid-template-columns: 1fr;
    }
}
</style>
</head>

<body>

<?php if ($message): ?>
<div class="alert <?= e($messageType) ?>">
    <i class="fas <?= $messageType === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle' ?>"></i>
    <?= e($message) ?>
</div>
<?php endif; ?>

<form method="POST" id="profileForm">
    <input type="hidden" name="id" value="<?= (int)$id ?>">
    <input type="hidden" name="csrf_token" value="<?= e($token) ?>">

    <!-- TOOLBAR -->
    <div class="toolbar">
        <button type="button" class="btn-toolbar btn-print" onclick="window.print()">
            <i class="fas fa-print"></i> Print Form
        </button>

        <button type="button" class="btn-toolbar btn-edit" id="editBtn" onclick="enableEdit()">
            <i class="fas fa-edit"></i> Edit Profile
        </button>

        <button type="submit" name="save_profile" value="1"
                class="btn-toolbar btn-save" id="saveBtn">
            <i class="fas fa-save"></i> Save Changes
        </button>

        <button type="button" class="btn-toolbar btn-cancel-edit"
                id="cancelBtn" onclick="cancelEdit()">
            <i class="fas fa-times"></i> Cancel
        </button>

        <button type="button" class="btn-toolbar btn-close"
                onclick="window.location.href='records.php'">
            <i class="fas fa-arrow-left"></i> Close
        </button>

        <span style="font-size:12px;color:var(--muted);margin-left:auto;">
            <i class="fas fa-print"></i>
            Printed: <?= e($printed) ?>
        </span>
    </div>

    <!-- DOCUMENT -->
    <div class="document" id="document">

        <!-- DSWD HEADER -->
        <div class="doc-header">
            <div class="logo-wrap">
                <div class="seal">🏛️</div>
                <div>
                    <h1>Department of Social Welfare and Development</h1>
                    <p>AICS — ASSISTANCE TO INDIVIDUALS IN CRISIS SITUATION</p>
                    <p>Batasan Hills &nbsp;|&nbsp; Quezon City</p>
                </div>
            </div>

            <div class="right-info">
                <div><strong>GENERAL INTAKE SHEET</strong></div>
                <div>DSWD-PMB-GF-011 | REV 02</div>
                <div style="margin-top:4px;">
                    Date Printed: <strong><?= e($printed) ?></strong>
                </div>
            </div>
        </div>

        <div class="accent-bar"></div>

        <!-- REFERENCE CODE -->
        <div class="code-banner">
            <div>
                <div style="font-size:10px;font-weight:800;color:var(--muted);text-transform:uppercase;letter-spacing:.8px;margin-bottom:4px;">
                    Application Reference Code
                </div>
                <div class="code-value">
                    <?= e($row['application_code'] ?? 'N/A') ?>
                </div>
            </div>

            <div style="text-align:right;">
                <div style="font-size:10px;color:var(--muted);margin-bottom:4px;">
                    Date of Request
                </div>
                <div style="font-weight:700;font-size:13px;">
                    <?= e($submitted) ?>
                </div>
                <div style="margin-top:6px;">
                    <span class="status-pill <?= e($statusClass) ?>">
                        <?= e($status) ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- CLASSIFICATION HEADER -->
        <div class="intake-class-grid">

            <div>
                <div class="intake-row view-value">
                    <span class="check-box"><?= strcasecmp(trim($row['program_type'] ?? 'AICS'), 'AICS') === 0 ? '✓' : '' ?></span>
                    AICS
                </div>
                <div class="intake-row view-value">
                    <span class="check-box"><?= strcasecmp(trim($row['program_type'] ?? ''), 'AKAP') === 0 ? '✓' : '' ?></span>
                    AKAP
                </div>
                <div class="intake-row view-value">
                    <span class="check-box"><?= (!in_array(strtoupper(trim($row['program_type'] ?? '')), ['AICS', 'AKAP'], true) && !empty($row['program_type'])) ? '✓' : '' ?></span>
                    Others
                </div>

                <?php if (hasField('program_type')): ?>
                <select class="edit-input" name="program_type">
                    <?php foreach (['AICS', 'AKAP', 'Others'] as $v): ?>
                    <option value="<?= e($v) ?>" <?= strcasecmp(trim($row['program_type'] ?? ''), $v) === 0 ? 'selected' : '' ?>>
                        <?= e($v) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
            </div>

            <div>
                <div class="intake-row view-value">
                    <span class="circle-box"><?= strcasecmp(trim($row['client_nature'] ?? 'NEW'), 'NEW') === 0 ? '✓' : '' ?></span>
                    New
                </div>
                <div class="intake-row view-value">
                    <span class="circle-box"><?= strcasecmp(trim($row['client_nature'] ?? ''), 'RETURNING') === 0 ? '✓' : '' ?></span>
                    Returning
                </div>

                <?php if (hasField('client_nature')): ?>
                <select class="edit-input" name="client_nature">
                    <?php foreach (['NEW', 'RETURNING'] as $v): ?>
                    <option value="<?= e($v) ?>" <?= strcasecmp(trim($row['client_nature'] ?? ''), $v) === 0 ? 'selected' : '' ?>>
                        <?= e($v) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
            </div>

            <div>
                <div class="intake-row view-value">
                    <span class="check-box"><?= strcasecmp(trim($row['intake_venue'] ?? 'ONSITE'), 'ONSITE') === 0 ? '✓' : '' ?></span>
                    Onsite
                </div>
                <div class="intake-row view-value">
                    <span class="check-box"><?= strcasecmp(trim($row['intake_venue'] ?? ''), 'MALASAKIT CENTER') === 0 ? '✓' : '' ?></span>
                    Malasakit Center
                </div>
                <div class="intake-row view-value">
                    <span class="check-box"><?= strcasecmp(trim($row['intake_venue'] ?? ''), 'OFFSITE') === 0 ? '✓' : '' ?></span>
                    Offsite
                </div>

                <?php if (hasField('intake_venue')): ?>
                <select class="edit-input" name="intake_venue">
                    <?php foreach (['ONSITE', 'MALASAKIT CENTER', 'OFFSITE'] as $v): ?>
                    <option value="<?= e($v) ?>" <?= strcasecmp(trim($row['intake_venue'] ?? ''), $v) === 0 ? 'selected' : '' ?>>
                        <?= e($v) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
            </div>

            <div style="flex-direction:row;align-items:center;justify-content:space-between;">
                <div style="display:flex;flex-direction:column;gap:6px;">
                    <div class="intake-row view-value">
                        <span class="circle-box"><?= strcasecmp(trim($row['intake_mode'] ?? 'WALK-IN'), 'WALK-IN') === 0 ? '✓' : '' ?></span>
                        Walk-in
                    </div>
                    <div class="intake-row view-value">
                        <span class="circle-box"><?= strcasecmp(trim($row['intake_mode'] ?? ''), 'REFERRAL') === 0 ? '✓' : '' ?></span>
                        Referral
                    </div>

                    <?php if (hasField('intake_mode')): ?>
                    <select class="edit-input" name="intake_mode">
                        <?php foreach (['WALK-IN', 'REFERRAL'] as $v): ?>
                        <option value="<?= e($v) ?>" <?= strcasecmp(trim($row['intake_mode'] ?? ''), $v) === 0 ? 'selected' : '' ?>>
                            <?= e($v) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>
                </div>

                <div style="text-align:center;">
                    <div style="margin-bottom:4px;font-size:11px;">Date :</div>
                    <div class="date-box-wrap">
                        <div style="display:flex;flex-direction:column;align-items:center;">
                            <div class="date-box"><?= date('m', $submittedTimestamp) ?></div>
                            <span style="font-size:8px;font-style:italic;color:#555;margin-top:2px;">MM</span>
                        </div>
                        <div style="display:flex;flex-direction:column;align-items:center;">
                            <div class="date-box"><?= date('d', $submittedTimestamp) ?></div>
                            <span style="font-size:8px;font-style:italic;color:#555;margin-top:2px;">DD</span>
                        </div>
                        <div style="display:flex;flex-direction:column;align-items:center;">
                            <div class="date-box" style="width:50px;"><?= date('Y', $submittedTimestamp) ?></div>
                            <span style="font-size:8px;font-style:italic;color:#555;margin-top:2px;">YYYY</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- PART I -->
        <div class="section-title">
            <i class="fas fa-user"></i>
            Part I: Beneficiary's Identifying Information
            <span class="sub">— Impormasyon ng Benepisyaryo</span>
        </div>

        <div class="info-body">

            <!-- NAME -->
            <div class="info-grid cols-4" style="margin-bottom:18px;">
                <?php textField('lname', 'Apelyido / Last Name', $row); ?>
                <?php textField('fname', 'Unang Pangalan / First Name', $row); ?>
                <?php textField('mname', 'Gitnang Pangalan / Middle Name', $row); ?>
                <?php textField('ext', 'Ext (Jr./Sr./III)', $row); ?>
            </div>

            <!-- BIRTH DATE, AGE, SEX -->
            <div class="info-grid" style="margin-bottom:18px;">
                <?php if (hasField('birth_date')): ?>
                <div class="info-item">
                    <label>Kapanganakan / Date of Birth</label>
                    <div class="filled-value view-value">
                        <?= e(displayDateValue($row['birth_date'] ?? '', $columnInfo['birth_date'])) ?>
                    </div>
                    <input class="edit-input" type="date" name="birth_date"
                           value="<?= e(($row['birth_date'] ?? '') === '0000-00-00' ? '' : ($row['birth_date'] ?? '')) ?>">
                </div>
                <?php endif; ?>

                <div class="info-item">
                    <label>Edad / Age</label>
                    <div class="filled-value"><?= e($age) ?></div>
                </div>

                <?php if (hasField('sex')): ?>
                <div class="info-item">
                    <label>Kasarian / Sex</label>
                    <div class="view-value" style="display:flex;gap:20px;padding-top:6px;">
                        <span class="check-item">
                            <span class="circle-box"><?= strcasecmp(trim($row['sex'] ?? ''), 'Male') === 0 ? '✓' : '' ?></span>
                            Male
                        </span>
                        <span class="check-item">
                            <span class="circle-box"><?= strcasecmp(trim($row['sex'] ?? ''), 'Female') === 0 ? '✓' : '' ?></span>
                            Female
                        </span>
                    </div>

                    <select class="edit-input" name="sex">
                        <option value="">Select Sex</option>
                        <?php foreach (['Male', 'Female'] as $v): ?>
                        <option value="<?= e($v) ?>" <?= strcasecmp(trim($row['sex'] ?? ''), $v) === 0 ? 'selected' : '' ?>>
                            <?= e($v) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
            </div>

            <!-- CIVIL STATUS, CONTACT, OCCUPATION -->
            <div class="info-grid" style="margin-bottom:18px;">
                <?php if (hasField('civil_status')): ?>
                <div class="info-item">
                    <label>Katayuang Sibil / Civil Status</label>

                    <div class="view-value" style="display:flex;flex-wrap:wrap;gap:8px 16px;padding-top:4px;font-size:11px;">
                        <?php foreach (['Single','Married','Widowed','Separated','Common-law'] as $cs): ?>
                        <span class="check-item">
                            <span class="circle-box"><?= strcasecmp(trim($row['civil_status'] ?? ''), $cs) === 0 ? '✓' : '' ?></span>
                            <?= e($cs) ?>
                        </span>
                        <?php endforeach; ?>
                    </div>

                    <select class="edit-input" name="civil_status">
                        <option value="">Select Civil Status</option>
                        <?php foreach (['Single','Married','Widowed','Separated','Common-law'] as $cs): ?>
                        <option value="<?= e($cs) ?>" <?= strcasecmp(trim($row['civil_status'] ?? ''), $cs) === 0 ? 'selected' : '' ?>>
                            <?= e($cs) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <?php
                textField('cp_number', 'Telepono / Contact Number', $row);
                textField('occupation', 'Trabaho / Occupation', $row);
                ?>
            </div>

            <!-- ADDRESS -->
            <div class="info-grid cols-4" style="margin-bottom:18px;">
                <?php textField('street', 'House No. / Street / Purok', $row); ?>
                <?php textField('barangay', 'Barangay', $row); ?>
                <?php textField('city', 'Lungsod / City', $row); ?>

                <?php if (hasField('region')): ?>
                    <?php textField('region', 'Rehiyon / Region', $row); ?>
                <?php else: ?>
                <div class="info-item">
                    <label>Rehiyon / Region</label>
                    <div class="filled-value">NCR</div>
                </div>
                <?php endif; ?>
            </div>

            <!-- ADDITIONAL ADDRESS FIELDS, IF PRESENT -->
            <div class="info-grid cols-2">
                <?php textField('municipality', 'Municipality', $row); ?>
                <?php textField('province', 'Province', $row); ?>
                <?php textField('postal_code', 'Postal Code', $row); ?>
                <?php textField('contact_number', 'Contact Number', $row); ?>
            </div>
        </div>

        <hr class="hr">

        <!-- PART II -->
        <div class="section-title red">
            <i class="fas fa-hand-holding-medical"></i>
            Part II: Assistance Details
            <span class="sub">— Detalye ng Tulong</span>
        </div>

        <div class="info-body">
            <div class="info-grid cols-2" style="margin-bottom:18px;">
                <?php selectField('assistance_type', 'Uri ng Tulong / Type of Assistance', $row, $types); ?>
                <?php selectField('client_category', 'Kategorya ng Kliyente / Client Category', $row, $categories); ?>
            </div>

            <div class="info-grid cols-full">
                <?php selectField('medical_cause', 'Sub-Kategorya at Medical Cause', $row, $subcategories); ?>
            </div>
        </div>

        <hr class="hr">

        <!-- FAMILY COMPOSITION -->
        <div class="section-title slate">
            <i class="fas fa-users"></i>
            Komposisyon ng Pamilya / Family Composition
        </div>

        <div class="info-body" style="padding-top:16px;padding-bottom:16px;">
            <div style="overflow-x:auto;">
                <table class="family-table">
                    <thead>
                        <tr>
                            <th style="width:35%;">Buong Pangalan <em style="font-weight:400;">Complete Name</em></th>
                            <th style="width:20%;">Relasyon <em style="font-weight:400;">Relationship</em></th>
                            <th style="width:10%;">Edad <em style="font-weight:400;">Age</em></th>
                            <th style="width:20%;">Trabaho <em style="font-weight:400;">Occupation</em></th>
                            <th style="width:15%;">Buwanang Kita <br><em>Monthly Salary</em></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($family)): ?>
                            <?php foreach ($family as $fam): ?>
                            <tr>
                                <td><?= e($fam['name'] ?? $fam['full_name'] ?? '') ?></td>
                                <td><?= e($fam['relation'] ?? $fam['relationship'] ?? '') ?></td>
                                <td><?= e($fam['age'] ?? '') ?></td>
                                <td><?= e($fam['occupation'] ?? '') ?></td>
                                <td>
                                    <?= !empty($fam['salary'])
                                        ? '₱ ' . number_format((float)$fam['salary'], 2)
                                        : '₱ 0.00' ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" style="text-align:center;color:var(--muted);font-style:italic;">
                                    Walang naitalang miyembro ng pamilya. / No family members recorded.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </div>

        <hr class="hr">

        <!-- PROBLEM PRESENTED -->
        <div class="section-title slate">
            <i class="fas fa-exclamation-circle"></i>
            PROBLEM PRESENTED
        </div>

        <div class="info-body" style="padding-top:16px;padding-bottom:16px;">
            <?php if (hasField('problem_presented')): ?>
            <div style="border:1.5px dashed var(--border);border-radius:8px;padding:16px;min-height:80px;background:#fafbff;">
                <div class="view-value" style="white-space:pre-wrap;font-size:12px;">
                    <?= e($row['problem_presented'] ?? 'No problem presented recorded.') ?>
                </div>
                <textarea class="edit-input" name="problem_presented"><?= e($row['problem_presented'] ?? '') ?></textarea>
            </div>
            <?php else: ?>
            <div style="border:1.5px dashed var(--border);border-radius:8px;padding:16px;min-height:120px;background:#fafbff;color:var(--muted);font-style:italic;">
            </div>
            <?php endif; ?>
        </div>

        <hr class="hr">

        <!-- SOCIAL WORKER ASSESSMENT -->
        <div class="section-title">
            <i class="fas fa-clipboard-check"></i>
            Social Worker's Assessment
            <span class="sub">— Para sa DSWD Personnel</span>
        </div>

        <div class="info-body">
            <?php
            $assessmentField = hasField('social_worker_assessment')
                ? 'social_worker_assessment'
                : (hasField('assessment') ? 'assessment' : null);
            ?>

            <div style="border:1.5px dashed var(--border);border-radius:8px;padding:16px;min-height:120px;background:#fafbff;">
                <?php if ($assessmentField): ?>
                <div class="view-value" style="white-space:pre-wrap;font-size:12px;">
                    <?= e($row[$assessmentField] ?? 'No assessment recorded.') ?>
                </div>
                <textarea class="edit-input" name="<?= e($assessmentField) ?>"><?= e($row[$assessmentField] ?? '') ?></textarea>
                <?php else: ?>
                <span style="color:var(--muted);font-style:italic;">
                </span>
                <?php endif; ?>
            </div>
        </div>

        <!-- PURPOSE / AMOUNT / FUND SOURCE -->
        <?php
        $hasPurposeTable = false;

        for ($i = 1; $i <= 3; $i++) {
            if (
                hasField('purpose_' . $i) ||
                hasField('amount_' . $i) ||
                hasField('fund_source_' . $i)
            ) {
                $hasPurposeTable = true;
            }
        }
        ?>

        <?php if ($hasPurposeTable): ?>
        <div class="info-body" style="padding-top:0;padding-bottom:16px;">
            <div style="overflow-x:auto;">
                <table class="family-table">
                    <thead>
                        <tr>
                            <th style="width:8%;text-align:center;">#</th>
                            <th style="width:52%;">Purpose of Assistance</th>
                            <th style="width:25%;">Amount</th>
                            <th style="width:15%;">Fund Source</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php for ($i = 1; $i <= 3; $i++):
                            $purposeField = 'purpose_' . $i;
                            $amountField = 'amount_' . $i;
                            $fundField = 'fund_source_' . $i;

                            $rawAmount = str_replace('₱', '', (string)($row[$amountField] ?? ''));
                            $cleanAmount = trim($rawAmount);

                            if (
                                !hasField($purposeField) &&
                                !hasField($amountField) &&
                                !hasField($fundField)
                            ) {
                                continue;
                            }
                        ?>
                        <tr>
                            <td style="text-align:center;"><?= $i ?></td>

                            <td>
                                <?php if (hasField($purposeField)): ?>
                                <span class="view-value"><?= e($row[$purposeField] ?? '') ?></span>
                                <input class="table-edit-input" type="text"
                                       name="<?= e($purposeField) ?>"
                                       value="<?= e($row[$purposeField] ?? '') ?>">
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if (hasField($amountField)): ?>
                                <span class="view-value">
                                    <?= $cleanAmount !== '' ? '₱ ' . e($cleanAmount) : '' ?>
                                </span>
                                <div class="table-edit-input" style="display:none;align-items:center;">
                                    <span style="padding:5px 8px;background:#eee;border:1px solid var(--navy);border-right:none;border-radius:4px 0 0 4px;font-weight:600;">₱</span>
                                    <input type="text" name="<?= e($amountField) ?>"
                                           value="<?= e($cleanAmount) ?>"
                                           style="border:1px solid var(--navy);border-radius:0 4px 4px 0;padding:5px 7px;width:100%;font-family:inherit;font-size:12px;">
                                </div>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if (hasField($fundField)): ?>
                                <span class="view-value"><?= e($row[$fundField] ?? '') ?></span>
                                <input class="table-edit-input" type="text"
                                       name="<?= e($fundField) ?>"
                                       value="<?= e($row[$fundField] ?? '') ?>">
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endfor; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- GENERAL PURPOSE / AMOUNT FIELDS IF THE DATABASE USES SINGLE COLUMNS -->
        <?php
        $singleAssistanceFields = [
            'purpose' => 'Purpose of Assistance',
            'purpose_of_assistance' => 'Purpose of Assistance',
            'assistance_purpose' => 'Assistance Purpose',
            'amount' => 'Assistance Amount',
            'assistance_amount' => 'Assistance Amount',
            'fund_source' => 'Fund Source',
            'source_of_fund' => 'Source of Fund'
        ];

        $singleExisting = [];

        foreach ($singleAssistanceFields as $field => $label) {
            if (hasField($field)) {
                $singleExisting[$field] = $label;
            }
        }
        ?>

        <?php if (!empty($singleExisting)): ?>
        <div class="info-body">
            <div class="info-grid cols-2">
                <?php foreach ($singleExisting as $field => $label): ?>
                    <?php
                    if (
                        in_array($field, ['purpose', 'purpose_of_assistance', 'assistance_purpose'], true) ||
                        in_array($field, ['amount', 'assistance_amount'], true)
                    ) {
                        longField($field, $label, $row);
                    } else {
                        textField($field, $label, $row);
                    }
                    ?>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="info-body">
            <div class="info-grid cols-2">
                <?php foreach ($otherFields as $field): ?>
                    <?php
                    $type = strtolower($columnInfo[$field]['Type'] ?? '');

                    if (
                        strpos($type, 'text') !== false ||
                        strpos($type, 'json') !== false
                    ) {
                        longField($field, fieldLabel($field), $row);
                    } else {
                        textField($field, fieldLabel($field), $row);
                    }
                    ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- SIGNATURE AREA -->
        <div class="sig-area">
            <div class="sig-box">
                <div class="sig-line"></div>
                <div style="font-size:11px;font-weight:700;color:var(--text);">
                    <?= e($fullname) ?>
                </div>
                <div class="sig-label">
                    Buong Pangalan at Pirma ng Kliyente<br>
                    (Signature over Printed Name)
                </div>
            </div>

            <div class="sig-box">
                <div class="sig-line"></div>
                <div style="font-size:11px;font-weight:700;color:var(--text);">
                    &nbsp;
                </div>
                <div class="sig-label">
                    Interviewed by / Reviewed & Approved by<br>
                    (Social Worker)
                </div>
            </div>
        </div>

        <!-- FOOTER -->
        <div class="doc-footer">
            <span>
                DSWD Field Office NCR &nbsp;|&nbsp;
                Batasan Hills &nbsp;|&nbsp; Quezon City
            </span>
            <span class="gold">
                AICS — Online Application System
            </span>
        </div>

    </div>
</form>

<script>
const documentElement = document.getElementById('document');
const profileForm = document.getElementById('profileForm');

const editBtn = document.getElementById('editBtn');
const saveBtn = document.getElementById('saveBtn');
const cancelBtn = document.getElementById('cancelBtn');

let originalValues = [];

function enableEdit() {
    originalValues = [];

    profileForm.querySelectorAll(
        '.edit-input, .table-edit-input'
    ).forEach(input => {
        if (input.matches('input, textarea, select')) {
            originalValues.push({
                element: input,
                value: input.value
            });
        } else {
            input.querySelectorAll('input, textarea, select').forEach(child => {
                originalValues.push({
                    element: child,
                    value: child.value
                });
            });
        }
    });

    documentElement.classList.add('edit-mode');

    editBtn.style.display = 'none';
    saveBtn.style.display = 'inline-flex';
    cancelBtn.style.display = 'inline-flex';
}

function cancelEdit() {
    originalValues.forEach(item => {
        item.element.value = item.value;
    });

    documentElement.classList.remove('edit-mode');

    editBtn.style.display = 'inline-flex';
    saveBtn.style.display = 'none';
    cancelBtn.style.display = 'none';
}

profileForm.addEventListener('submit', function(event) {
    if (!confirm('Save the changes to this beneficiary profile?')) {
        event.preventDefault();
    }
});

window.addEventListener('DOMContentLoaded', function() {
    cancelEdit();
});
</script>

</body>
</html>

<?php
$conn->close();
?>