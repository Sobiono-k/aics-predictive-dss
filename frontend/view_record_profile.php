<?php
ob_start();

date_default_timezone_set('Asia/Manila');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../db.php';

function h($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$id || $id <= 0) {
    exit('Invalid request.');
}

/* =========================================================
   CSRF
========================================================= */
if (empty($_SESSION['record_profile_token'])) {
    $_SESSION['record_profile_token'] = bin2hex(random_bytes(32));
}

$token = $_SESSION['record_profile_token'];

/* =========================================================
   GET DATABASE COLUMN INFORMATION
========================================================= */
$columnInfo = [];

$result = $conn->query("SHOW FULL COLUMNS FROM `aics_sample_data`");

if (!$result) {
    exit('Unable to read database columns: ' . h($conn->error));
}

while ($column = $result->fetch_assoc()) {
    $columnInfo[$column['Field']] = $column;
}

$columns = array_keys($columnInfo);

/*
 * Every actual database column is editable except:
 * - Primary record ID
 * - Automatically generated columns
 *
 * This means new columns added to aics_sample_data
 * will automatically appear in this profile.
 */
$editableFields = [];

foreach ($columnInfo as $field => $info) {
    if ($field === 'id') {
        continue;
    }

    if (stripos($info['Extra'] ?? '', 'auto_increment') !== false) {
        continue;
    }

    if (stripos($info['Extra'] ?? '', 'GENERATED') !== false) {
        continue;
    }

    $editableFields[] = $field;
}

/* =========================================================
   FIELD TYPE HELPERS
========================================================= */
function getInputType(array $info): string {
    $type = strtolower($info['Type'] ?? '');

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

function isLongField(array $info): bool {
    $type = strtolower($info['Type'] ?? '');

    return (
        str_contains($type, 'text') ||
        str_contains($type, 'json') ||
        in_array(strtolower($info['Field']), [
            'remarks',
            'problem_presented',
            'social_worker_assessment',
            'assessment',
            'family_composition'
        ], true)
    );
}

function displayValue($value, array $info): string {
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

function fieldLabel(string $field): string {
    $special = [
        'id_number' => 'Beneficiary ID Number',
        'application_code' => 'Application Reference Code',
        'request_date' => 'Request Date',
        'fname' => 'First Name',
        'mname' => 'Middle Name',
        'lname' => 'Last Name',
        'ext' => 'Name Extension',
        'birth_date' => 'Birthdate',
        'sex' => 'Sex',
        'civil_status' => 'Civil Status',
        'cp_number' => 'Contact Number',
        'contact_number' => 'Contact Number',
        'client_category' => 'Client Category',
        'client_subcategory' => 'Client Subcategory',
        'medical_cause' => 'Medical Cause',
        'assistance_type' => 'Type of Assistance',
        'family_composition' => 'Family Composition',
        'program_type' => 'Program Type',
        'client_nature' => 'Client Nature',
        'intake_venue' => 'Intake Venue',
        'intake_mode' => 'Intake Mode',
        'fund_source' => 'Fund Source',
        'amount' => 'Assistance Amount',
        'purpose' => 'Purpose of Assistance',
        'problem_presented' => 'Problem Presented',
        'social_worker_assessment' => 'Social Worker Assessment'
    ];

    if (isset($special[$field])) {
        return $special[$field];
    }

    return ucwords(str_replace('_', ' ', $field));
}

/* =========================================================
   SAVE PROFILE
========================================================= */
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_profile'])) {

    if (!hash_equals($token, $_POST['csrf_token'] ?? '')) {
        exit('Invalid security token. Please reload the page.');
    }

    $updates = [];
    $values = [];
    $types = '';

    foreach ($editableFields as $field) {

        if (!array_key_exists($field, $_POST)) {
            continue;
        }

        $value = trim((string)$_POST[$field]);
        $info = $columnInfo[$field];
        $sqlType = strtolower($info['Type'] ?? '');

        if ($value === '') {
            $value = null;
        }

        // Convert datetime-local value to MySQL DATETIME format.
        if ($value !== null && preg_match('/^(datetime|timestamp)\b/', $sqlType)) {
            $value = str_replace('T', ' ', $value);

            if (strlen($value) === 16) {
                $value .= ':00';
            }

            $date = DateTime::createFromFormat('Y-m-d H:i:s', $value);

            if (!$date) {
                $message = 'Invalid date/time value for ' . fieldLabel($field) . '.';
                $messageType = 'error';
                break;
            }

            $value = $date->format('Y-m-d H:i:s');
        }

        // Validate DATE fields.
        if ($value !== null && preg_match('/^date\b/', $sqlType)) {
            $date = DateTime::createFromFormat('Y-m-d', $value);

            if (!$date || $date->format('Y-m-d') !== $value) {
                $message = 'Invalid date value for ' . fieldLabel($field) . '.';
                $messageType = 'error';
                break;
            }
        }

        // Do not put NULL into a NOT NULL column.
        if ($value === null && ($info['Null'] ?? 'YES') === 'NO') {
            $value = '';
        }

        $updates[] = "`$field` = ?";
        $values[] = $value;

        if (preg_match('/^(tinyint|smallint|mediumint|int|bigint)/', $sqlType)) {
            $types .= 'i';
        } elseif (preg_match('/^(decimal|float|double)/', $sqlType)) {
            $types .= 'd';
        } else {
            $types .= 's';
        }
    }

    if ($messageType !== 'error' && !empty($updates)) {

        $sql = "UPDATE `aics_sample_data`
                SET " . implode(', ', $updates) . "
                WHERE `id` = ?";

        $values[] = $id;
        $types .= 'i';

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            $message = 'Unable to prepare database update: ' . $conn->error;
            $messageType = 'error';
        } else {
            // Bind parameters by reference for dynamic fields.
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
            } else {
                $message = 'Database update failed: ' . $stmt->error;
                $messageType = 'error';
            }

            $stmt->close();
        }
    }
}

/* =========================================================
   FLASH MESSAGE
========================================================= */
if (isset($_SESSION['record_profile_flash'])) {
    $message = $_SESSION['record_profile_flash']['message'];
    $messageType = $_SESSION['record_profile_flash']['type'];

    unset($_SESSION['record_profile_flash']);
}

/* =========================================================
   LOAD LATEST BENEFICIARY RECORD
========================================================= */
$stmt = $conn->prepare("SELECT * FROM `aics_sample_data` WHERE `id` = ? LIMIT 1");
$stmt->bind_param('i', $id);
$stmt->execute();

$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    exit('Beneficiary record not found.');
}

$fname = $row['fname'] ?? '';
$mname = $row['mname'] ?? '';
$lname = $row['lname'] ?? '';
$ext = $row['ext'] ?? '';

$fullname = strtoupper(trim(
    $fname . ' ' .
    ($mname ? $mname . ' ' : '') .
    $lname . ' ' .
    $ext
));

$printed = date('F d, Y g:i A');

$reference = $row['application_code']
    ?? $row['id_number']
    ?? 'N/A';

$status = $row['status'] ?? 'Pending';

$statusClass = in_array(
    strtolower($status),
    ['approved', 'claimed', 'completed'],
    true
) ? 'claimed' : '';

/* =========================================================
   ORGANIZE ALL DATABASE FIELDS INTO FORM SECTIONS
========================================================= */
$sections = [
    'classification' => [
        'title' => 'Application Classification',
        'icon' => 'fa-clipboard-list',
        'fields' => [
            'program_type',
            'client_nature',
            'intake_venue',
            'intake_mode',
            'request_date',
            'application_code',
            'id_number'
        ]
    ],

    'personal' => [
        'title' => 'Part I — Impormasyon ng Benepisyaryo',
        'subtitle' => "Beneficiary's Identifying Information",
        'icon' => 'fa-user',
        'fields' => [
            'fname',
            'mname',
            'lname',
            'ext',
            'birth_date',
            'sex',
            'civil_status',
            'occupation',
            'cp_number',
            'contact_number',
            'email'
        ]
    ],

    'address' => [
        'title' => 'Address Information',
        'icon' => 'fa-location-dot',
        'fields' => [
            'street',
            'address',
            'barangay',
            'city',
            'municipality',
            'province',
            'region',
            'postal_code'
        ]
    ],

    'assistance' => [
        'title' => 'Part II — Detalye ng Tulong',
        'subtitle' => 'Assistance Details',
        'icon' => 'fa-hand-holding-medical',
        'fields' => [
            'assistance_type',
            'medical_cause',
            'client_category',
            'client_subcategory',
            'purpose',
            'purpose_of_assistance',
            'assistance_purpose',
            'purpose_1',
            'purpose_2',
            'purpose_3',
            'amount',
            'assistance_amount',
            'amount_1',
            'amount_2',
            'amount_3',
            'fund_source',
            'source_of_fund',
            'fund_source_1',
            'fund_source_2',
            'fund_source_3'
        ]
    ],

    'assessment' => [
        'title' => 'Social Worker Assessment',
        'icon' => 'fa-clipboard-check',
        'fields' => [
            'problem_presented',
            'social_worker_assessment',
            'assessment',
            'remarks',
            'family_composition'
        ]
    ],

    'status' => [
        'title' => 'Record Status and Other Information',
        'icon' => 'fa-database',
        'fields' => [
            'status'
        ]
    ]
];

// Avoid showing the same field twice.
$usedFields = [];
foreach ($sections as &$section) {
    $section['fields'] = array_values(array_filter(
        $section['fields'],
        function ($field) use ($columns, &$usedFields) {
            if (!in_array($field, $columns, true) || isset($usedFields[$field])) {
                return false;
            }

            $usedFields[$field] = true;
            return true;
        }
    ));
}
unset($section);

// Any database columns not listed above are still displayed and editable.
$otherFields = [];

foreach ($editableFields as $field) {
    if (!isset($usedFields[$field])) {
        $otherFields[] = $field;
    }
}

if (!empty($otherFields)) {
    $sections['other'] = [
        'title' => 'Other Database Information',
        'icon' => 'fa-table',
        'fields' => $otherFields
    ];
}

/* =========================================================
   RENDER FIELD
========================================================= */
function renderProfileField(
    string $field,
    array $row,
    array $columnInfo
): void {

    $info = $columnInfo[$field];
    $value = $row[$field] ?? '';
    $label = fieldLabel($field);

    $long = isLongField($info);
    $inputType = getInputType($info);

    echo '<div class="info-item">';

    echo '<label>' . h($label) . '</label>';

    if ($long) {
        echo '<div class="view-value value multiline">'
            . nl2br(h($value !== '' ? $value : '—'))
            . '</div>';

        echo '<textarea class="edit-input" name="' . h($field) . '">'
            . h($value)
            . '</textarea>';

    } else {
        $display = displayValue($value, $info);

        echo '<div class="view-value value">'
            . h($display)
            . '</div>';

        $inputValue = $value;

        if ($inputType === 'datetime-local' && $inputValue !== '') {
            $inputValue = str_replace(' ', 'T', substr((string)$inputValue, 0, 16));
        }

        echo '<input class="edit-input"'
            . ' type="' . h($inputType) . '"'
            . ' name="' . h($field) . '"'
            . ' value="' . h($inputValue) . '"'
            . ($inputType === 'number' ? ' step="any"' : '')
            . '>';
    }

    echo '</div>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>General Intake Sheet — <?= h($fullname) ?></title>

<link rel="icon" type="image/png" href="<?= defined('BASE_URL') ? BASE_URL : '' ?>images/dswdlogo2.png">

<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<style>
:root {
    --navy:#003893;
    --red:#ce1126;
    --gold:#c8a94a;
    --light:#f0f4ff;
    --border:#000;
    --muted:#475569;
}

* {
    box-sizing:border-box;
    margin:0;
    padding:0;
}

body {
    font-family:'Inter',sans-serif;
    background:#e8ecf2;
    color:#000;
    padding:24px;
}

.toolbar {
    max-width:820px;
    margin:0 auto 16px;
    display:flex;
    gap:10px;
    align-items:center;
    flex-wrap:wrap;
}

.btn-toolbar {
    padding:10px 18px;
    border-radius:8px;
    border:none;
    font-family:inherit;
    font-size:13px;
    font-weight:700;
    cursor:pointer;
    display:flex;
    align-items:center;
    gap:8px;
}

.btn-print {background:var(--navy);color:#fff;}
.btn-close {background:#fff;color:#475569;border:1px solid #cbd5e1;}
.btn-edit {background:#f59e0b;color:#fff;}
.btn-save {background:#10b981;color:#fff;}
.btn-cancel {background:#64748b;color:#fff;}

.document {
    max-width:820px;
    margin:auto;
    background:#fff;
    border:2px solid #000;
    box-shadow:0 8px 30px rgba(0,0,0,.12);
}

.doc-header {
    background:var(--navy);
    color:#fff;
    padding:18px 28px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:16px;
}

.logo-wrap {display:flex;align-items:center;gap:14px;}

.seal {
    width:56px;
    height:56px;
    background:#fff;
    border-radius:50%;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:26px;
}

.doc-header h1 {
    font-size:15px;
    font-weight:800;
    text-transform:uppercase;
}

.doc-header p {
    font-size:10px;
    opacity:.8;
    margin-top:3px;
}

.right-info {
    text-align:right;
    font-size:11px;
    line-height:1.7;
}

.accent-bar {
    height:5px;
    background:repeating-linear-gradient(
        90deg,
        var(--gold) 0,
        var(--gold) 20px,
        var(--red) 20px,
        var(--red) 40px
    );
}

.code-banner {
    background:var(--light);
    border-bottom:2px solid #000;
    padding:12px 28px;
    display:flex;
    justify-content:space-between;
    align-items:center;
    flex-wrap:wrap;
    gap:10px;
}

.code {
    font-family:monospace;
    font-size:20px;
    font-weight:800;
    color:var(--navy);
    letter-spacing:2px;
    overflow-wrap:anywhere;
}

.status-pill {
    display:inline-block;
    padding:4px 12px;
    border-radius:20px;
    font-size:11px;
    font-weight:800;
    text-transform:uppercase;
    background:#fef9c3;
    color:#854d0e;
}

.status-pill.claimed {
    background:#dcfce7;
    color:#166534;
}

.section-title {
    background:var(--navy);
    color:#fff;
    padding:8px 28px;
    font-size:11px;
    font-weight:800;
    text-transform:uppercase;
    letter-spacing:1px;
    display:flex;
    align-items:center;
    gap:8px;
}

.section-title.red {background:var(--red);}
.section-title.slate {background:#475569;}

.section-title .sub {
    font-weight:400;
    font-size:10px;
    font-style:italic;
    text-transform:none;
}

.info-body {padding:16px 28px;}

.info-grid {
    display:grid;
    grid-template-columns:repeat(3,minmax(0,1fr));
    gap:12px 20px;
}

.info-item {
    min-width:0;
}

.info-item label {
    font-size:9px;
    font-weight:800;
    color:var(--muted);
    text-transform:uppercase;
    letter-spacing:.8px;
    display:block;
    margin-bottom:3px;
}

.info-item .value {
    font-size:13px;
    font-weight:700;
    border:1px solid #94a3b8;
    border-radius:3px;
    background:#f8fafc;
    padding:6px 10px;
    min-height:32px;
    display:flex;
    align-items:center;
    text-transform:uppercase;
    overflow-wrap:anywhere;
    white-space:pre-wrap;
}

.info-item .value.multiline {
    display:block;
    min-height:80px;
    white-space:pre-wrap;
    text-transform:none;
    overflow-wrap:anywhere;
}

.edit-input {
    display:none;
    width:100%;
    min-height:34px;
    padding:7px 9px;
    border:1.5px solid var(--navy);
    border-radius:4px;
    font-family:inherit;
    font-size:12px;
    font-weight:600;
    color:#000;
    background:#fff;
}

textarea.edit-input {
    min-height:90px;
    resize:vertical;
}

.edit-mode .edit-input {display:block;}
.edit-mode .view-value {display:none!important;}

.hr {border:0;border-top:1.5px solid #000;}

.sig-area {
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:40px;
    padding:20px 28px;
    border-top:2px solid #000;
}

.sig-box {text-align:center;}
.sig-line {border-bottom:1.5px solid #000;height:35px;margin-bottom:4px;}
.sig-label {font-size:9px;color:#334155;font-weight:700;}

.doc-footer {
    background:var(--navy);
    padding:10px 28px;
    display:flex;
    justify-content:space-between;
    gap:10px;
    font-size:10px;
    color:rgba(255,255,255,.7);
}

.gold {color:var(--gold);font-weight:700;}

.alert {
    max-width:820px;
    margin:0 auto 12px;
    padding:12px 16px;
    border-radius:7px;
    font-size:13px;
    font-weight:700;
}

.alert.success {background:#dcfce7;color:#166534;}
.alert.error {background:#fee2e2;color:#991b1b;}

@media print {
    * {
        -webkit-print-color-adjust:exact!important;
        print-color-adjust:exact!important;
    }

    body {background:#fff;padding:0;}
    .toolbar,.alert {display:none!important;}
    .edit-input {display:none!important;}
    .view-value {display:flex!important;}
    .info-item .value.multiline {display:block!important;}
    .document {
        box-shadow:none;
        max-width:100%;
    }

    @page {margin:8mm;size:A4;}
}

@media(max-width:600px) {
    body {padding:10px;}
    .info-grid {grid-template-columns:1fr 1fr;}
    .sig-area {grid-template-columns:1fr;}
    .doc-header {flex-direction:column;align-items:flex-start;}
    .right-info {text-align:left;}
    .doc-footer {flex-direction:column;}
}

@media(max-width:380px) {
    .info-grid {grid-template-columns:1fr;}
}
</style>
</head>

<body>

<?php if ($message): ?>
<div class="alert <?= h($messageType) ?>">
    <?= h($message) ?>
</div>
<?php endif; ?>

<div class="toolbar">

    <button type="button" class="btn-toolbar btn-edit" id="editBtn" onclick="enableEdit()">
        <i class="fas fa-pen"></i> Edit Profile
    </button>

    <button type="submit"
            form="profileForm"
            name="save_profile"
            value="1"
            class="btn-toolbar btn-save"
            id="saveBtn"
            style="display:none;">
        <i class="fas fa-save"></i> Save Changes
    </button>

    <button type="button"
            class="btn-toolbar btn-cancel"
            id="cancelBtn"
            onclick="cancelEdit()"
            style="display:none;">
        <i class="fas fa-times"></i> Cancel
    </button>

    <button type="button" class="btn-toolbar btn-print" onclick="window.print()">
        <i class="fas fa-print"></i> Print Profile
    </button>

    <button type="button"
            class="btn-toolbar btn-close"
            onclick="window.location.href='records.php'">
        <i class="fas fa-arrow-left"></i> Close
    </button>

    <span style="font-size:11px;color:var(--muted);margin-left:auto;">
        Printed: <?= h($printed) ?>
    </span>
</div>

<form method="POST" id="profileForm">
<input type="hidden" name="csrf_token" value="<?= h($token) ?>">

<div class="document" id="document">

    <!-- HEADER -->
    <div class="doc-header">
        <div class="logo-wrap">
            <div class="seal">🏛️</div>
            <div>
                <h1>Department of Social Welfare and Development</h1>
                <p>AICS — ASSISTANCE TO INDIVIDUALS IN CRISIS SITUATION</p>
                <p>Batasan Hills Branch | Quezon City</p>
            </div>
        </div>

        <div class="right-info">
            <strong>GENERAL INTAKE SHEET</strong>
            <div>DSWD-PMB-GF-011 | REV 02</div>
            <div>Date Printed: <?= h($printed) ?></div>
        </div>
    </div>

    <div class="accent-bar"></div>

    <!-- APPLICATION REFERENCE -->
    <div class="code-banner">
        <div>
            <div style="font-size:9px;font-weight:800;color:var(--muted);">
                APPLICATION REFERENCE CODE
            </div>
            <div class="code"><?= h($reference) ?></div>
        </div>

        <div style="text-align:right;">
            <div style="font-size:9px;color:var(--muted);">Submission Status</div>

            <span class="status-pill <?= h($statusClass) ?>">
                <?= h($status) ?>
            </span>

            <div style="font-size:9px;margin-top:4px;">
                Submitted:
                <?= !empty($row['request_date'])
                    ? h(displayValue($row['request_date'], $columnInfo['request_date']))
                    : 'N/A' ?>
            </div>
        </div>
    </div>

    <!-- DYNAMIC DATABASE SECTIONS -->
    <?php foreach ($sections as $sectionKey => $section): ?>

        <?php if (empty($section['fields'])) continue; ?>

        <div class="section-title <?= $sectionKey === 'assessment' ? 'slate' : '' ?>">
            <i class="fas <?= h($section['icon']) ?>"></i>

            <?= h($section['title']) ?>

            <?php if (!empty($section['subtitle'])): ?>
                <span class="sub">(<?= h($section['subtitle']) ?>)</span>
            <?php endif; ?>
        </div>

        <div class="info-body">
            <div class="info-grid">

                <?php foreach ($section['fields'] as $field): ?>

                    <?php renderProfileField($field, $row, $columnInfo); ?>

                <?php endforeach; ?>

            </div>
        </div>

        <hr class="hr">

    <?php endforeach; ?>

    <!-- SIGNATURES -->
    <div class="sig-area">
        <div class="sig-box">
            <div class="sig-line"></div>

            <div style="font-size:11px;font-weight:700;">
                <?= h($fullname) ?>
            </div>

            <div class="sig-label">
                Buong Pangalan at Pirma ng Kliyente<br>
                (Signature over Printed Name)
            </div>
        </div>

        <div class="sig-box">
            <div class="sig-line"></div>

            <div style="font-size:11px;font-weight:700;">&nbsp;</div>

            <div class="sig-label">
                Interviewed by / Reviewed & Approved by<br>
                (Social Worker)
            </div>
        </div>
    </div>

    <!-- FOOTER -->
    <div class="doc-footer">
        <span>DSWD Field Office NCR | Batasan Hills Branch | Quezon City</span>
        <span class="gold">AICS — Online Application System</span>
    </div>

</div>
</form>

<script>
const documentElement = document.getElementById('document');
const editBtn = document.getElementById('editBtn');
const saveBtn = document.getElementById('saveBtn');
const cancelBtn = document.getElementById('cancelBtn');
const profileForm = document.getElementById('profileForm');

let originalValues = [];

function enableEdit() {
    originalValues = [];

    profileForm.querySelectorAll('.edit-input').forEach(input => {
        originalValues.push({
            element: input,
            value: input.value
        });
    });

    documentElement.classList.add('edit-mode');

    editBtn.style.display = 'none';
    saveBtn.style.display = 'flex';
    cancelBtn.style.display = 'flex';
}

function cancelEdit() {
    originalValues.forEach(item => {
        item.element.value = item.value;
    });

    documentElement.classList.remove('edit-mode');

    editBtn.style.display = 'flex';
    saveBtn.style.display = 'none';
    cancelBtn.style.display = 'none';
}

profileForm.addEventListener('submit', function(event) {
    if (!confirm('Save the changes to this beneficiary profile?')) {
        event.preventDefault();
    }
});
</script>

</body>
</html>

<?php
$conn->close();
?>
