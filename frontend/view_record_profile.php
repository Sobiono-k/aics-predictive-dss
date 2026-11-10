
<?php
// view_record_profile.php
date_default_timezone_set('Asia/Manila');

require_once 'auth.php';
require_once(__DIR__ . '/../db.php');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function h($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id <= 0) {
    exit('Invalid request.');
}

// CSRF protection
if (empty($_SESSION['record_profile_token'])) {
    $_SESSION['record_profile_token'] = bin2hex(random_bytes(32));
}

$token = $_SESSION['record_profile_token'];

function getColumns(mysqli $conn, string $table): array {
    $columns = [];
    $result = $conn->query("SHOW COLUMNS FROM `$table`");

    if ($result) {
        while ($column = $result->fetch_assoc()) {
            $columns[] = $column['Field'];
        }
    }

    return $columns;
}

$columns = getColumns($conn, 'aics_sample_data');

// Fields that may be edited.
// Only fields actually present in the database will be updated.
$editableFields = [
    'application_code',
    'request_date',
    'program_type',
    'client_nature',
    'intake_venue',
    'intake_mode',

    'fname',
    'mname',
    'lname',
    'ext',
    'birth_date',
    'sex',
    'civil_status',
    'cp_number',

    'barangay',
    'city',
    'municipality',
    'province',
    'region',
    'address',
    'street',

    'assistance_type',
    'medical_cause',
    'client_category',
    'client_subcategory',
    'purpose',
    'purpose_of_assistance',
    'amount',
    'fund_source',
    'remarks',

    'problem_presented',
    'social_worker_assessment',
    'assessment',
    'occupation',
    'family_composition'
];

$editableFields = array_values(array_intersect($editableFields, $columns));

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

        // Empty strings become NULL for optional database fields.
        if ($value === '') {
            $value = null;
        }

        $updates[] = "`$field` = ?";
        $values[] = $value;
        $types .= 's';
    }

    if (!empty($updates)) {

        $sql = "UPDATE aics_sample_data SET "
             . implode(', ', $updates)
             . " WHERE id = ?";

        $values[] = $id;
        $types .= 'i';

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            $message = 'Unable to prepare update: ' . $conn->error;
            $messageType = 'error';
        } else {

            $stmt->bind_param($types, ...$values);

            if ($stmt->execute()) {
                $message = 'Beneficiary profile successfully updated.';
                $messageType = 'success';
            } else {
                $message = 'Update failed: ' . $stmt->error;
                $messageType = 'error';
            }

            $stmt->close();
        }
    }
}

// Always retrieve the latest saved record.
$stmt = $conn->prepare("SELECT * FROM aics_sample_data WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();

$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    exit('Record not found.');
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

function fieldValue(array $row, string $field, string $fallback = ''): string {
    return (string)($row[$field] ?? $fallback);
}

function showField(
    array $row,
    string $field,
    string $label,
    array $editableFields,
    string $type = 'text',
    string $class = ''
): void {

    $value = fieldValue($row, $field);
    $editable = in_array($field, $editableFields, true);

    echo '<div class="info-item ' . h($class) . '">';
    echo '<label>' . h($label) . '</label>';

    echo '<div class="view-value value ' . h($class) . '">'
       . h($value !== '' ? $value : '—')
       . '</div>';

    if ($editable) {
        echo '<input class="edit-input" type="' . h($type) . '"'
           . ' name="' . h($field) . '"'
           . ' value="' . h($value) . '">';
    }

    echo '</div>';
}

function showTextarea(
    array $row,
    string $field,
    string $label,
    array $editableFields
): void {

    $value = fieldValue($row, $field);

    echo '<div class="info-item">';
    echo '<label>' . h($label) . '</label>';

    echo '<div class="view-value value multiline">'
       . nl2br(h($value !== '' ? $value : '—'))
       . '</div>';

    if (in_array($field, $editableFields, true)) {
        echo '<textarea class="edit-input" name="' . h($field) . '">'
           . h($value)
           . '</textarea>';
    }

    echo '</div>';
}

function showSelect(
    array $row,
    string $field,
    string $label,
    array $options,
    array $editableFields
): void {

    $value = fieldValue($row, $field);

    echo '<div class="info-item">';
    echo '<label>' . h($label) . '</label>';

    echo '<div class="view-value value">' . h($value ?: '—') . '</div>';

    if (in_array($field, $editableFields, true)) {

        echo '<select class="edit-input" name="' . h($field) . '">';

        echo '<option value="">Select</option>';

        foreach ($options as $option) {
            $selected = ($value === $option) ? 'selected' : '';
            echo '<option value="' . h($option) . '" ' . $selected . '>'
               . h($option)
               . '</option>';
        }

        echo '</select>';
    }

    echo '</div>';
}

$family = [];

if (!empty($row['family_composition'])) {
    $decoded = json_decode($row['family_composition'], true);

    if (is_array($decoded)) {
        $family = $decoded;
    }
}

// Discover optional purpose/amount/fund-source columns.
$purposeFields = [
    'purpose_of_assistance',
    'purpose',
    'assistance_purpose'
];

$amountFields = [
    'amount',
    'assistance_amount'
];

$fundFields = [
    'fund_source',
    'source_of_fund'
];

$purposeField = null;
$amountField = null;
$fundField = null;

foreach ($purposeFields as $field) {
    if (in_array($field, $columns, true)) {
        $purposeField = $field;
        break;
    }
}

foreach ($amountFields as $field) {
    if (in_array($field, $columns, true)) {
        $amountField = $field;
        break;
    }
}

foreach ($fundFields as $field) {
    if (in_array($field, $columns, true)) {
        $fundField = $field;
        break;
    }
}

$status = $row['status'] ?? 'Pending';
$statusClass = strtolower($status);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>General Intake Sheet — <?= h($fullname) ?></title>

<link rel="icon" type="image/png" href="<?= BASE_URL ?>images/dswdlogo2.png">

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
    width:56px;height:56px;
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

.intake-class-grid {
    display:grid;
    grid-template-columns:1.2fr 1fr 1.2fr 1.8fr;
    border-bottom:2px solid #000;
    font-size:11px;
    font-weight:700;
}

.intake-class-grid > div {
    padding:10px 12px;
    border-right:1px solid #000;
    display:flex;
    flex-direction:column;
    justify-content:center;
    gap:6px;
}

.intake-row {
    display:flex;
    align-items:center;
    gap:6px;
    text-transform:uppercase;
}

.checkbox-box,.circle-box {
    width:14px;
    height:14px;
    border:1px solid #000;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    font-weight:900;
}

.circle-box {border-radius:50%;}

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
}

.status-pill {
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
    grid-template-columns:repeat(3,1fr);
    gap:12px 20px;
}

.info-grid.cols-2 {grid-template-columns:1fr 1fr;}
.info-grid.cols-4 {grid-template-columns:repeat(4,1fr);}
.info-grid.cols-full {grid-template-columns:1fr;}

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
    min-height:30px;
    display:flex;
    align-items:center;
    text-transform:uppercase;
    overflow-wrap:anywhere;
}

.info-item .value.highlight {color:var(--navy);}
.info-item .value.green {color:#059669;}
.info-item .value.multiline {
    display:block;
    min-height:80px;
    white-space:normal;
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

.family-table {
    width:100%;
    border-collapse:collapse;
    font-size:11px;
}

.family-table th,.family-table td {
    border:1px solid #94a3b8;
    padding:7px 8px;
    text-align:left;
}

.family-table th {
    background:#f1f5f9;
    font-size:9px;
    text-transform:uppercase;
}

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
    .document {
        box-shadow:none;
        max-width:100%;
    }
    @page {margin:8mm;size:A4;}
}

@media(max-width:600px) {
    body {padding:10px;}
    .info-grid,.info-grid.cols-4 {grid-template-columns:1fr 1fr;}
    .sig-area {grid-template-columns:1fr;}
    .doc-header {flex-direction:column;align-items:flex-start;}
    .right-info {text-align:left;}
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

    <button type="submit" form="profileForm" name="save_profile"
            value="1" class="btn-toolbar btn-save" id="saveBtn" style="display:none;">
        <i class="fas fa-save"></i> Save Changes
    </button>

    <button type="button" class="btn-toolbar btn-cancel"
            id="cancelBtn" onclick="cancelEdit()" style="display:none;">
        <i class="fas fa-times"></i> Cancel
    </button>

    <button type="button" class="btn-toolbar btn-print" onclick="window.print()">
        <i class="fas fa-print"></i> Print Profile
    </button>

    <button type="button" class="btn-toolbar btn-close"
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

    <div class="intake-class-grid">
        <div>
            <div class="intake-row">
                <span class="checkbox-box"><?= ($row['program_type'] ?? '') === 'AICS' ? '✓' : '' ?></span> AICS
            </div>
            <div class="intake-row">
                <span class="checkbox-box"><?= ($row['program_type'] ?? '') === 'AKAP' ? '✓' : '' ?></span> AKAP
            </div>
            <div class="intake-row">
                <span class="checkbox-box"><?= !in_array($row['program_type'] ?? '', ['AICS','AKAP']) && !empty($row['program_type']) ? '✓' : '' ?></span> Others
            </div>
        </div>

        <div>
            <div class="intake-row">
                <span class="circle-box"><?= ($row['client_nature'] ?? '') === 'NEW' ? '✓' : '' ?></span> New
            </div>
            <div class="intake-row">
                <span class="circle-box"><?= ($row['client_nature'] ?? '') === 'RETURNING' ? '✓' : '' ?></span> Returning
            </div>
        </div>

        <div>
            <div class="intake-row">
                <span class="checkbox-box"><?= ($row['intake_venue'] ?? '') === 'ONSITE' ? '✓' : '' ?></span> Onsite
            </div>
            <div class="intake-row">
                <span class="checkbox-box"><?= ($row['intake_venue'] ?? '') === 'MALASAKIT CENTER' ? '✓' : '' ?></span> Malasakit Center
            </div>
            <div class="intake-row">
                <span class="checkbox-box"><?= ($row['intake_venue'] ?? '') === 'OFFSITE' ? '✓' : '' ?></span> Offsite
            </div>
        </div>

        <div style="display:flex;flex-direction:row;justify-content:space-between;">
            <div>
                <div class="intake-row">
                    <span class="circle-box"><?= ($row['intake_mode'] ?? '') === 'WALK-IN' ? '✓' : '' ?></span> Walk-in
                </div>
                <div class="intake-row">
                    <span class="circle-box"><?= ($row['intake_mode'] ?? '') === 'REFERRAL' ? '✓' : '' ?></span> Referral
                </div>
            </div>

            <div style="text-align:center;">
                <div style="font-size:9px;">Date</div>
                <?= !empty($row['request_date']) ? h(date('m/d/Y', strtotime($row['request_date']))) : '—' ?>
            </div>
        </div>
    </div>

    <div class="code-banner">
        <div>
            <div style="font-size:9px;font-weight:800;color:var(--muted);">
                APPLICATION REFERENCE CODE
            </div>
            <div class="code"><?= h($row['application_code'] ?? 'N/A') ?></div>
        </div>

        <div style="text-align:right;">
            <div style="font-size:9px;color:var(--muted);">Submission Status</div>
            <span class="status-pill <?= in_array($statusClass,['approved','claimed','completed']) ? 'claimed' : '' ?>">
                <?= h($status) ?>
            </span>
            <div style="font-size:9px;margin-top:4px;">
                Submitted: <?= !empty($row['request_date']) ? h(date('F d, Y g:i A', strtotime($row['request_date']))) : 'N/A' ?>
            </div>
        </div>
    </div>

    <div class="section-title">
        <i class="fas fa-user"></i>
        Part I — Impormasyon ng Benepisyaryo
        <span class="sub">(Beneficiary's Identifying Information)</span>
    </div>

    <div class="info-body">

        <div class="info-grid cols-4" style="margin-bottom:12px;">
            <?php
            showField($row,'lname','Apelyido / Last Name',$editableFields);
            showField($row,'fname','Unang Pangalan / First Name',$editableFields);
            showField($row,'mname','Gitnang Pangalan / Middle Name',$editableFields);
            showField($row,'ext','Extension',$editableFields);
            ?>
        </div>

        <div class="info-grid cols-4" style="margin-bottom:12px;">
            <?php
            showField($row,'birth_date','Kapanganakan / Birthdate',$editableFields,'date');
            ?>
            <div class="info-item">
                <label>Edad / Age</label>
                <div class="value">
                    <?php
                    if (!empty($row['birth_date']) && $row['birth_date'] !== '0000-00-00') {
                        try {
                            echo (new DateTime($row['birth_date']))->diff(new DateTime('today'))->y;
                        } catch (Exception $e) {
                            echo '—';
                        }
                    } else {
                        echo '—';
                    }
                    ?>
                </div>
            </div>

            <?php
            showSelect($row,'sex','Kasarian / Sex',['MALE','FEMALE'],$editableFields);
            showSelect($row,'civil_status','Katayuang Sibil / Civil Status',
                ['SINGLE','MARRIED','WIDOWED','SEPARATED','LIVE-IN'],$editableFields);
            ?>
        </div>

        <div class="info-grid cols-2" style="margin-bottom:12px;">
            <?php
            showField($row,'cp_number','Telepono / Mobile Number',$editableFields);
            showField($row,'barangay','Barangay',$editableFields);
            ?>
        </div>

        <div class="info-grid cols-2" style="margin-bottom:12px;">
            <?php
            if (in_array('city',$editableFields,true)) {
                showField($row,'city','Lungsod / City',$editableFields);
            } elseif (in_array('municipality',$editableFields,true)) {
                showField($row,'municipality','Lungsod / Municipality',$editableFields);
            }

            if (in_array('region',$editableFields,true)) {
                showField($row,'region','Rehiyon / Region',$editableFields);
            } else {
                echo '<div class="info-item"><label>Region</label><div class="value">'
                   . h($row['region'] ?? 'NCR')
                   . '</div></div>';
            }
            ?>
        </div>

        <?php if (in_array('address',$editableFields,true) || in_array('street',$editableFields,true)): ?>
        <div class="info-grid cols-full">
            <?php
            if (in_array('address',$editableFields,true)) {
                showField($row,'address','Complete Address',$editableFields);
            } else {
                showField($row,'street','Street Address',$editableFields);
            }
            ?>
        </div>
        <?php endif; ?>

    </div>

    <hr class="hr">

    <div class="section-title">
        <i class="fas fa-hand-holding-medical"></i>
        Part II — Detalye ng Tulong
        <span class="sub">(Assistance Details)</span>
    </div>

    <div class="info-body">

        <div class="info-grid cols-2" style="margin-bottom:12px;">
            <?php
            showField($row,'assistance_type','Uri ng Tulong / Type of Assistance',$editableFields);
            showField($row,'medical_cause','Medical Cause',$editableFields);
            ?>
        </div>

        <div class="info-grid cols-2" style="margin-bottom:12px;">
            <?php
            showField($row,'client_category','Kategorya ng Kliyente / Client Category',$editableFields);
            showField($row,'client_subcategory','Sub-Category',$editableFields);
            ?>
        </div>

        <div class="info-grid cols-2" style="margin-bottom:12px;">
            <?php
            showField($row,'program_type','Program Type',$editableFields);
            showField($row,'application_code','Application Reference Code',$editableFields);
            ?>
        </div>

        <div class="info-grid cols-2" style="margin-bottom:12px;">
            <?php
            showField($row,'client_nature','Client Nature',$editableFields);
            showField($row,'intake_venue','Intake Venue',$editableFields);
            ?>
        </div>

        <div class="info-grid cols-2">
            <?php
            showField($row,'intake_mode','Intake Mode',$editableFields);
            showField($row,'request_date','Request Date',$editableFields,'datetime-local');
            ?>
        </div>

    </div>

    <hr class="hr">

    <div class="section-title slate">
        <i class="fas fa-exclamation-circle"></i>
        PROBLEM PRESENTED
    </div>

    <div class="info-body">
        <?php
        if (in_array('problem_presented',$editableFields,true)) {
            showTextarea($row,'problem_presented','Problem Presented',$editableFields);
        } elseif (in_array('remarks',$editableFields,true)) {
            showTextarea($row,'remarks','Problem Presented / Remarks',$editableFields);
        } else {
            echo '<div class="value multiline">'
               . h($row['remarks'] ?? '—')
               . '</div>';
        }
        ?>
    </div>

    <hr class="hr">

    <div class="section-title">
        <i class="fas fa-clipboard-check"></i>
        Social Worker's Assessment
        <span class="sub">— Para sa DSWD Personnel</span>
    </div>

    <div class="info-body">
        <?php
        if (in_array('social_worker_assessment',$editableFields,true)) {
            showTextarea($row,'social_worker_assessment',"Social Worker's Assessment",$editableFields);
        } elseif (in_array('assessment',$editableFields,true)) {
            showTextarea($row,'assessment',"Social Worker's Assessment",$editableFields);
        } else {
            echo '<div class="value multiline">—</div>';
        }
        ?>
    </div>

    <div class="info-body">
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
                <?php for ($i = 0; $i < 3; $i++): ?>
                <tr>
                    <td style="text-align:center;"><?= $i + 1 ?></td>
                    <td>
                        <?php if ($i === 0 && $purposeField): ?>
                            <div class="view-value"><?= h($row[$purposeField] ?? '—') ?></div>
                            <input class="edit-input" name="<?= h($purposeField) ?>"
                                   value="<?= h($row[$purposeField] ?? '') ?>">
                        <?php else: ?>
                            <?= $i === 0 ? h($row['assistance_type'] ?? '—') : '—' ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($i === 0 && $amountField): ?>
                            <div class="view-value"><?= h($row[$amountField] ?? '—') ?></div>
                            <input class="edit-input" type="number" step="0.01"
                                   name="<?= h($amountField) ?>"
                                   value="<?= h($row[$amountField] ?? '') ?>">
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($i === 0 && $fundField): ?>
                            <div class="view-value"><?= h($row[$fundField] ?? '—') ?></div>
                            <input class="edit-input" name="<?= h($fundField) ?>"
                                   value="<?= h($row[$fundField] ?? '') ?>">
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endfor; ?>
            </tbody>
        </table>
    </div>

    <?php if (in_array('remarks',$editableFields,true) && !in_array('problem_presented',$editableFields,true)): ?>
    <div class="info-body">
        <?php showTextarea($row,'remarks','Additional Remarks',$editableFields); ?>
    </div>
    <?php endif; ?>

    <div class="sig-area">
        <div class="sig-box">
            <div class="sig-line"></div>
            <div style="font-size:11px;font-weight:700;"><?= h($fullname) ?></div>
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

// Convert a saved datetime value to the datetime-local input format.
document.querySelectorAll('input[type="datetime-local"]').forEach(input => {
    if (input.value && input.value.includes(' ')) {
        input.value = input.value.replace(' ', 'T').substring(0, 16);
    }
});
</script>

</body>
</html>

<?php $conn->close(); ?>
