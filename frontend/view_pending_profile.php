<?php
// view_pending_profile.php — DSWD General Intake Sheet with Inline Editing
date_default_timezone_set('Asia/Manila');

require_once 'auth.php';
require_once(__DIR__ . '/../db.php');

$id = isset($_GET['id']) ? (int)$_GET['id'] : (int)($_POST['id'] ?? 0);

if (!$id) {
    exit("Invalid request.");
}

// CSRF protection
if (empty($_SESSION['pending_profile_csrf'])) {
    $_SESSION['pending_profile_csrf'] = bin2hex(random_bytes(32));
}

// =====================================================
// CONFIRMATION
// =====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_applicant'])) {

    if (
        empty($_POST['csrf_token']) ||
        !hash_equals($_SESSION['pending_profile_csrf'], $_POST['csrf_token'])
    ) {
        exit("Invalid security token.");
    }

    try {
        $conn->begin_transaction();

        // Fetch and lock pending application
        $stmt = $conn->prepare("
            SELECT *
            FROM pending_applications
            WHERE id = ?
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $pending = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$pending) {
            throw new Exception("Pending application not found.");
        }

        if (!empty($pending['is_claimed'])) {
            throw new Exception("This application has already been confirmed.");
        }

        // Get actual columns in beneficiary records
        $destinationColumns = [];
        $result = $conn->query("SHOW COLUMNS FROM aics_sample_data");

        if (!$result) {
            throw new Exception($conn->error);
        }

        while ($column = $result->fetch_assoc()) {
            $destinationColumns[] = $column['Field'];
        }

        // Map fields whose names differ between the two tables
        $fieldMap = [
            'id_number'   => 'application_code',
            'request_date' => 'submitted_at'
        ];

        $insertData = [];

        // Copy every matching field automatically
        foreach ($destinationColumns as $column) {

            // Never copy the destination auto-increment ID
            if ($column === 'id') {
                continue;
            }

            if (array_key_exists($column, $pending)) {
                $insertData[$column] = $pending[$column];
            } elseif (isset($fieldMap[$column])) {
                $sourceField = $fieldMap[$column];

                if (array_key_exists($sourceField, $pending)) {
                    $insertData[$column] = $pending[$sourceField];
                }
            }
        }

        // Explicitly set the beneficiary record status
        if (in_array('status', $destinationColumns, true)) {
            $insertData['status'] = 'Approved';
        }

        // Make sure the application code is saved as the record ID number
        if (empty($insertData['id_number'])) {
            throw new Exception("Application reference code is missing.");
        }

        // Prevent duplicate beneficiary records
        $check = $conn->prepare("
            SELECT id
            FROM aics_sample_data
            WHERE id_number = ?
            LIMIT 1
        ");

        $check->bind_param("s", $insertData['id_number']);
        $check->execute();
        $duplicate = $check->get_result()->fetch_assoc();
        $check->close();

        if ($duplicate) {
            throw new Exception("This applicant already exists in Beneficiary Records.");
        }

        // Build INSERT using the columns actually present in the table
        $columns = array_keys($insertData);
        $quotedColumns = array_map(
            fn($column) => "`" . str_replace("`", "``", $column) . "`",
            $columns
        );

        $placeholders = implode(',', array_fill(0, count($columns), '?'));

        $insertSql = "
            INSERT INTO aics_sample_data
            (" . implode(',', $quotedColumns) . ")
            VALUES ($placeholders)
        ";

        $insert = $conn->prepare($insertSql);

        if (!$insert) {
            throw new Exception($conn->error);
        }

        $values = array_values($insertData);
        $types = str_repeat('s', count($values));

        $bindParams = [$types];

        foreach ($values as $key => $value) {
            $bindParams[] = &$values[$key];
        }

        call_user_func_array([$insert, 'bind_param'], $bindParams);

        if (!$insert->execute()) {
            throw new Exception($insert->error);
        }

        $insert->close();

        // Mark original pending application as completed
        $update = $conn->prepare("
            UPDATE pending_applications
            SET status = 'Completed',
                is_claimed = 1
            WHERE id = ?
        ");

        $update->bind_param("i", $id);

        if (!$update->execute()) {
            throw new Exception($update->error);
        }

        $update->close();

        $conn->commit();

        header("Location: records.php?msg=confirmed");
        exit();

    } catch (Throwable $e) {

        $conn->rollback();

        exit(
            "Unable to confirm applicant: " .
            htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')
        );
    }
}

// =====================================================
// SAVE CHANGES - SAME PAGE
// =====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_changes'])) {

    if (
        !isset($_POST['csrf_token']) ||
        !hash_equals($_SESSION['pending_profile_csrf'], $_POST['csrf_token'])
    ) {
        exit("Invalid security token. Please refresh the page.");
    }

    $fields = [
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
        'occupation',
        'street',
        'barangay',
        'city',
        'client_category',
        'medical_cause',
        'assistance_type',
        'purpose_1', 'amount_1', 'fund_source_1',
        'purpose_2', 'amount_2', 'fund_source_2',
        'purpose_3', 'amount_3', 'fund_source_3'
    ];

    $values = [];

    foreach ($fields as $field) {
        $val = trim($_POST[$field] ?? '');
        if (in_array($field, ['amount_1', 'amount_2', 'amount_3'])) {
            $val = trim(str_replace(['₱', ''], '', $val));
        }
        $values[$field] = $val;
    }

    if (
        $values['fname'] === '' ||
        $values['lname'] === '' ||
        $values['birth_date'] === '' ||
        $values['sex'] === '' ||
        $values['cp_number'] === ''
    ) {
        exit("Please complete all required fields.");
    }

    $date = DateTime::createFromFormat('!Y-m-d', $values['birth_date']);

    if (!$date || $date->format('Y-m-d') !== $values['birth_date']) {
        exit("Invalid birth date.");
    }

    $sql = "UPDATE pending_applications SET
        program_type = ?,
        client_nature = ?,
        intake_venue = ?,
        intake_mode = ?,
        fname = ?,
        mname = ?,
        lname = ?,
        ext = ?,
        birth_date = ?,
        sex = ?,
        civil_status = ?,
        cp_number = ?,
        occupation = ?,
        street = ?,
        barangay = ?,
        city = ?,
        client_category = ?,
        medical_cause = ?,
        assistance_type = ?,
        purpose_1 = ?, amount_1 = ?, fund_source_1 = ?,
        purpose_2 = ?, amount_2 = ?, fund_source_2 = ?,
        purpose_3 = ?, amount_3 = ?, fund_source_3 = ?
        WHERE id = ?";

    $update = $conn->prepare($sql);

    if (!$update) {
        exit("Unable to prepare update: " . htmlspecialchars($conn->error));
    }

    $types = "ssssssssssssssssssssssssssssi";

    $update->bind_param(
        $types,
        $values['program_type'],
        $values['client_nature'],
        $values['intake_venue'],
        $values['intake_mode'],
        $values['fname'],
        $values['mname'],
        $values['lname'],
        $values['ext'],
        $values['birth_date'],
        $values['sex'],
        $values['civil_status'],
        $values['cp_number'],
        $values['occupation'],
        $values['street'],
        $values['barangay'],
        $values['city'],
        $values['client_category'],
        $values['medical_cause'],
        $values['assistance_type'],
        $values['purpose_1'], $values['amount_1'], $values['fund_source_1'],
        $values['purpose_2'], $values['amount_2'], $values['fund_source_2'],
        $values['purpose_3'], $values['amount_3'], $values['fund_source_3'],
        $id
    );

    if ($update->execute()) {
        $update->close();
        header("Location: view_pending_profile.php?id=" . $id . "&updated=1");
        exit();
    }

    $error = $update->error;
    $update->close();

    exit("Failed to update applicant: " . htmlspecialchars($error));
}

// =====================================================
// FETCH APPLICANT
// =====================================================
$stmt = $conn->prepare("SELECT * FROM pending_applications WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    exit("<p style='font-family:sans-serif;padding:40px;color:#ef4444;'>Record not found.</p>");
}

function e($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

// Reusable text field
function textField($name, $label, $row, $type = 'text') {
    ?>
    <div class="info-item">
        <label><?php echo e($label); ?></label>
        <div class="filled-value view-value">
            <?php echo e($row[$name] ?? ''); ?>
        </div>
        <input
            class="edit-input"
            type="<?php echo e($type); ?>"
            name="<?php echo e($name); ?>"
            value="<?php echo e($row[$name] ?? ''); ?>"
            <?php echo in_array($name, ['fname', 'lname', 'birth_date', 'cp_number']) ? 'required' : ''; ?>
        >
    </div>
    <?php
}

// Reusable select field
function selectField($name, $label, $row, $options) {
    ?>
    <div class="info-item">
        <label><?php echo e($label); ?></label>
        <div class="filled-value view-value">
            <?php echo e($row[$name] ?? ''); ?>
        </div>
        <select class="edit-input" name="<?php echo e($name); ?>">
            <option value="">Select</option>
            <?php foreach ($options as $option): ?>
                <option value="<?php echo e($option); ?>"
                    <?php echo (strcasecmp(trim($row[$name] ?? ''), $option) === 0) ? 'selected' : ''; ?>>
                    <?php echo e($option); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php
}

$fullname = strtoupper(trim(
    ($row['fname'] ?? '') . ' ' .
    (!empty($row['mname']) ? $row['mname'] . ' ' : '') .
    ($row['lname'] ?? '') .
    (!empty($row['ext']) ? ' ' . $row['ext'] : '')
));

$bdate = (!empty($row['birth_date']) && $row['birth_date'] !== '0000-00-00')
    ? date("F d, Y", strtotime($row['birth_date'])) : '';

$submitted = (!empty($row['submitted_at']) && $row['submitted_at'] !== '0000-00-00 00:00:00')
    ? date("F d, Y", strtotime($row['submitted_at'])) : date("F d, Y");

$printed = date("F d, Y g:i A");

$categories = [
    "Family Heads and Other Needy Adult",
    "Persons with Disabilities",
    "Senior Citizens",
    "Men/Women in Specially Difficult Circumstances"
];

$subcategories = [
    "Speech Impairment",
    "Psychosocial Disability",
    "Learning Disability",
    "Cancer",
    "Deaf/Hard-of-Hearing",
    "Mental Disability",
    "Visual Disability",
    "Intellectual Disability",
    "Physical Disability",
    "Rare Disease"
];

$types = ["Medical Assistance", "Cash Assistance"];

$family = [];
if (!empty($row['family_composition'])) {
    $decoded = json_decode($row['family_composition'], true);
    if (is_array($decoded)) {
        $family = $decoded;
    }
}
?>
<!DOCTYPE html>
<html lang="fil">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>General Intake Sheet — <?php echo e($fullname); ?></title>

    <link rel="icon" type="image/png" href="<?php echo defined('BASE_URL') ? BASE_URL : '../'; ?>images/dswdlogo2.png">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        :root {
            --navy:  #003893;
            --red:   #ce1126;
            --gold:  #c8a94a;
            --light: #f0f4ff;
            --border:#d1d9e6;
            --text:  #1e293b;
            --muted: #64748b;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Inter', sans-serif;
            background: #e8ecf2;
            color: var(--text);
            padding: 24px;
        }

        .toolbar {
            max-width: 780px;
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
            display: flex;
            align-items: center;
            gap: 8px;
            transition: .2s;
            text-decoration: none;
        }

        .btn-print { background: var(--navy); color: #fff; }
        .btn-print:hover { background: #002a6d; }

        .btn-close {
            background: #fff;
            color: var(--muted);
            border: 1px solid var(--border);
        }

        .btn-close:hover { background: #f1f5f9; }

        .btn-confirm-toolbar { background: #10b981; color: #fff; }
        .btn-confirm-toolbar:hover { background: #059669; }

        .btn-edit { background: #f59e0b; color: #fff; }
        .btn-save { background: #10b981; color: #fff; display: none; }
        .btn-cancel-edit { background: #64748b; color: #fff; display: none; }

        .document {
            max-width: 780px;
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

        .doc-header .logo-wrap { display: flex; align-items: center; gap: 14px; }

        .doc-header .seal {
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

        .doc-header h1 { font-size: 15px; font-weight: 800; line-height: 1.2; }
        .doc-header p { font-size: 10px; opacity: .75; margin-top: 3px; letter-spacing: .5px; }
        .doc-header .right-info { text-align: right; font-size: 11px; opacity: .85; line-height: 1.7; }

        .accent-bar {
            height: 5px;
            background: repeating-linear-gradient(90deg, var(--gold) 0, var(--gold) 20px, var(--red) 20px, var(--red) 40px);
        }

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

        .code-banner .code-value {
            font-family: 'Courier New', monospace;
            font-size: 20px;
            font-weight: 800;
            color: var(--navy);
        }

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
        }

        .intake-class-grid > div:last-child { border-right: none; }

        .intake-row { display: flex; align-items: center; gap: 6px; }
        .date-box-wrap { display: flex; align-items: center; gap: 4px; }

        .date-box {
            border: 1px solid #000;
            height: 22px;
            text-align: center;
            font-size: 11px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
        }

        .section-title {
            background: var(--navy);
            color: #fff;
            padding: 8px 28px;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 1px;
            display: flex;
            align-items: center;
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

        .info-body { padding: 20px 28px; }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px 24px;
        }

        .info-grid.cols-2 { grid-template-columns: 1fr 1fr; }
        .info-grid.cols-4 { grid-template-columns: 2fr 1.5fr 1fr 1fr; }
        .info-grid.cols-full { grid-template-columns: 1fr; }

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
        }

        .check-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            color: var(--text);
        }

        .check-box {
            width: 14px;
            height: 14px;
            border: 1.5px solid #000;
            border-radius: 2px;
            flex-shrink: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            font-weight: 900;
        }

        .circle-box {
            width: 14px;
            height: 14px;
            border: 1.5px solid #000;
            border-radius: 50%;
            flex-shrink: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 9px;
            font-weight: 900;
        }

        .family-table { width: 100%; border-collapse: collapse; font-size: 12px; }

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

        .family-table tr:last-child td { border-bottom: none; }

        .sig-area {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 40px;
            padding: 24px 28px;
            border-top: 1.5px solid var(--border);
        }

        .sig-box { text-align: center; }
        .sig-line { border-bottom: 1.5px solid var(--text); margin-bottom: 6px; height: 40px; }

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
        }

        .doc-footer span.gold { color: var(--gold); font-weight: 700; }

        .hr { border: 0; border-top: 1px solid #e2e8f0; margin: 0; }

        /* INLINE EDITING */
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

        .edit-mode .edit-input { display: block; }
        .edit-mode .view-value { display: none !important; }

        .edit-mode .info-item .edit-input {
            margin-top: 4px;
        }

        @media print {
            * {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }

            body { background: #fff; padding: 0; }
            .toolbar { display: none !important; }
            .edit-input { display: none !important; }
            .document { box-shadow: none; border: none; max-width: 100%; }

            @page { margin: 12mm 14mm; size: A4; }
        }
    </style>
</head>

<body>

<?php if (isset($_GET['updated']) && $_GET['updated'] == '1'): ?>
    <div style="max-width:780px;margin:0 auto 15px;padding:13px 16px;background:#dcfce7;color:#166534;border-radius:8px;font-size:13px;font-weight:700;">
        <i class="fas fa-check-circle"></i>
        Applicant profile successfully updated!
    </div>
<?php endif; ?>

<form method="POST" id="profileForm">
    <input type="hidden" name="id" value="<?php echo $id; ?>">
    <input type="hidden" name="csrf_token" value="<?php echo e($_SESSION['pending_profile_csrf']); ?>">

    <!-- SCREEN TOOLBAR -->
    <div class="toolbar">

        <button type="button" class="btn-toolbar btn-print" onclick="window.print()">
            <i class="fas fa-print"></i> Print Form
        </button>

        <button type="button" class="btn-toolbar btn-edit" id="editBtn" onclick="enableEdit()">
            <i class="fas fa-edit"></i> Edit Form
        </button>

        <button type="submit" name="save_changes" value="1"
                class="btn-toolbar btn-save" id="saveBtn">
            <i class="fas fa-save"></i> Save Changes
        </button>

        <button type="button" class="btn-toolbar btn-cancel-edit"
                id="cancelBtn" onclick="cancelEdit()">
            <i class="fas fa-times"></i> Cancel
        </button>

        <?php if (empty($row['is_claimed'])): ?>
            <button type="submit" name="confirm_applicant" value="1" class="btn-toolbar btn-confirm-toolbar">
                <i class="fas fa-check-circle"></i> Confirm & Save to Records
            </button>
        <?php endif; ?>

        <button type="button" class="btn-toolbar btn-close" onclick="window.location.href='records.php'">
            <i class="fas fa-arrow-left"></i> Close
        </button>

        <span style="font-size:12px;color:var(--muted);margin-left:auto;">
            <i class="fas fa-print"></i> Printed: <?php echo $printed; ?>
        </span>

    </div>

    <!-- PRINTABLE DOCUMENT -->
    <div class="document">

        <!-- HEADER -->
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
                <div style="margin-top:4px;">Date Printed: <strong><?php echo $printed; ?></strong></div>
            </div>
        </div>

        <div class="accent-bar"></div>

        <!-- REFERENCE CODE -->
        <div class="code-banner">
            <div>
                <div style="font-size:10px;font-weight:800;color:var(--muted);text-transform:uppercase;letter-spacing:.8px;margin-bottom:4px;">
                    Application Reference Code
                </div>
                <div class="code-value"><?php echo e($row['application_code'] ?? 'N/A'); ?></div>
            </div>

            <div style="text-align:right;">
                <div style="font-size:10px;color:var(--muted);margin-bottom:4px;">Date of Request</div>
                <div style="font-weight:700;font-size:13px;"><?php echo $submitted; ?></div>
            </div>
        </div>

        <!-- CLASSIFICATION HEADER -->
        <div class="intake-class-grid">

            <div>
                <div class="intake-row view-value">
                    <span class="check-box"><?php echo (strcasecmp(trim($row['program_type'] ?? 'AICS'), 'AICS') === 0) ? '✓' : ''; ?></span> AICS
                </div>
                <div class="intake-row view-value">
                    <span class="check-box"><?php echo (strcasecmp(trim($row['program_type'] ?? ''), 'AKAP') === 0) ? '✓' : ''; ?></span> AKAP
                </div>
                <div class="intake-row view-value">
                    <span class="check-box"><?php echo (!in_array(strtoupper(trim($row['program_type'] ?? '')), ['AICS', 'AKAP']) && !empty($row['program_type'])) ? '✓' : ''; ?></span> Others
                </div>

                <select class="edit-input" name="program_type">
                    <?php foreach (['AICS', 'AKAP', 'Others'] as $v): ?>
                        <option value="<?php echo e($v); ?>" <?php echo (strcasecmp(trim($row['program_type'] ?? ''), $v) === 0) ? 'selected' : ''; ?>>
                            <?php echo e($v); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <div class="intake-row view-value">
                    <span class="circle-box"><?php echo (strcasecmp(trim($row['client_nature'] ?? 'NEW'), 'NEW') === 0) ? '✓' : ''; ?></span> New
                </div>
                <div class="intake-row view-value">
                    <span class="circle-box"><?php echo (strcasecmp(trim($row['client_nature'] ?? ''), 'RETURNING') === 0) ? '✓' : ''; ?></span> Returning
                </div>

                <select class="edit-input" name="client_nature">
                    <?php foreach (['NEW', 'RETURNING'] as $v): ?>
                        <option value="<?php echo e($v); ?>" <?php echo (strcasecmp(trim($row['client_nature'] ?? ''), $v) === 0) ? 'selected' : ''; ?>>
                            <?php echo e($v); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <div class="intake-row view-value">
                    <span class="check-box"><?php echo (strcasecmp(trim($row['intake_venue'] ?? 'ONSITE'), 'ONSITE') === 0) ? '✓' : ''; ?></span> Onsite
                </div>
                <div class="intake-row view-value">
                    <span class="check-box"><?php echo (strcasecmp(trim($row['intake_venue'] ?? ''), 'MALASAKIT CENTER') === 0) ? '✓' : ''; ?></span> Malasakit Center
                </div>
                <div class="intake-row view-value">
                    <span class="check-box"><?php echo (strcasecmp(trim($row['intake_venue'] ?? ''), 'OFFSITE') === 0) ? '✓' : ''; ?></span> Offsite
                </div>

                <select class="edit-input" name="intake_venue">
                    <?php foreach (['ONSITE', 'MALASAKIT CENTER', 'OFFSITE'] as $v): ?>
                        <option value="<?php echo e($v); ?>" <?php echo (strcasecmp(trim($row['intake_venue'] ?? ''), $v) === 0) ? 'selected' : ''; ?>>
                            <?php echo e($v); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="flex-direction:row;align-items:center;justify-content:space-between;">
                <div style="display:flex;flex-direction:column;gap:6px;">
                    <div class="intake-row view-value">
                        <span class="circle-box"><?php echo (strcasecmp(trim($row['intake_mode'] ?? 'WALK-IN'), 'WALK-IN') === 0) ? '✓' : ''; ?></span> Walk-in
                    </div>
                    <div class="intake-row view-value">
                        <span class="circle-box"><?php echo (strcasecmp(trim($row['intake_mode'] ?? ''), 'REFERRAL') === 0) ? '✓' : ''; ?></span> Referral
                    </div>

                    <select class="edit-input" name="intake_mode">
                        <?php foreach (['WALK-IN', 'REFERRAL'] as $v): ?>
                            <option value="<?php echo e($v); ?>" <?php echo (strcasecmp(trim($row['intake_mode'] ?? ''), $v) === 0) ? 'selected' : ''; ?>>
                                <?php echo e($v); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="text-align:center;">
                    <div style="margin-bottom:4px;font-size:11px;">Date :</div>
                    <div class="date-box-wrap">
                        <div style="display:flex;flex-direction:column;align-items:center;">
                            <div class="date-box" style="width:32px;"><?php echo !empty($row['submitted_at']) ? date('m', strtotime($row['submitted_at'])) : date('m'); ?></div>
                            <span style="font-size:8px;font-style:italic;font-weight:400;color:#555;margin-top:2px;">MM</span>
                        </div>
                        <div style="display:flex;flex-direction:column;align-items:center;">
                            <div class="date-box" style="width:32px;"><?php echo !empty($row['submitted_at']) ? date('d', strtotime($row['submitted_at'])) : date('d'); ?></div>
                            <span style="font-size:8px;font-style:italic;font-weight:400;color:#555;margin-top:2px;">DD</span>
                        </div>
                        <div style="display:flex;flex-direction:column;align-items:center;">
                            <div class="date-box" style="width:50px;"><?php echo !empty($row['submitted_at']) ? date('Y', strtotime($row['submitted_at'])) : date('Y'); ?></div>
                            <span style="font-size:8px;font-style:italic;font-weight:400;color:#555;margin-top:2px;">YYYY</span>
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

            <div class="info-grid" style="margin-bottom:18px;">
                <div class="info-item">
                    <label>Kapanganakan / Date of Birth</label>
                    <div class="filled-value view-value"><?php echo e($bdate); ?></div>
                    <input class="edit-input" type="date" name="birth_date"
                           value="<?php echo e($row['birth_date'] ?? ''); ?>" required>
                </div>

                <div class="info-item">
                    <label>Edad / Age</label>
                    <div class="filled-value">
                        <?php
                        if (!empty($row['birth_date']) && $row['birth_date'] !== '0000-00-00') {
                            $from = new DateTime($row['birth_date']);
                            $to = new DateTime('today');
                            echo $from->diff($to)->y;
                        } else {
                            echo '—';
                        }
                        ?>
                    </div>
                </div>

                <div class="info-item">
                    <label>Kasarian / Sex</label>
                    <div class="view-value" style="display:flex;gap:20px;padding-top:6px;">
                        <span class="check-item">
                            <span class="circle-box"><?php echo (strcasecmp(trim($row['sex'] ?? ''), 'Male') === 0) ? '✓' : ''; ?></span> Male
                        </span>
                        <span class="check-item">
                            <span class="circle-box"><?php echo (strcasecmp(trim($row['sex'] ?? ''), 'Female') === 0) ? '✓' : ''; ?></span> Female
                        </span>
                    </div>

                    <select class="edit-input" name="sex" required>
                        <option value="">Select Sex</option>
                        <?php foreach (['Male', 'Female'] as $v): ?>
                            <option value="<?php echo e($v); ?>" <?php echo (strcasecmp(trim($row['sex'] ?? ''), $v) === 0) ? 'selected' : ''; ?>>
                                <?php echo e($v); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="info-grid" style="margin-bottom:18px;">
                <div class="info-item">
                    <label>Katayuang Sibil / Civil Status</label>

                    <div class="view-value" style="display:flex;flex-wrap:wrap;gap:8px 16px;padding-top:4px;font-size:11px;">
                        <?php foreach (['Single','Married','Widowed','Separated','Common-law'] as $cs): ?>
                            <span class="check-item">
                                <span class="circle-box"><?php echo (strcasecmp(trim($row['civil_status'] ?? ''), $cs) === 0) ? '✓' : ''; ?></span>
                                <?php echo e($cs); ?>
                            </span>
                        <?php endforeach; ?>
                    </div>

                    <select class="edit-input" name="civil_status">
                        <option value="">Select Civil Status</option>
                        <?php foreach (['Single','Married','Widowed','Separated','Common-law'] as $cs): ?>
                            <option value="<?php echo e($cs); ?>" <?php echo (strcasecmp(trim($row['civil_status'] ?? ''), $cs) === 0) ? 'selected' : ''; ?>>
                                <?php echo e($cs); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php textField('cp_number', 'Telepono / Contact Number', $row); ?>
                <?php textField('occupation', 'Trabaho / Occupation', $row); ?>
            </div>

            <!-- ADDRESS ROW (STREET, BARANGAY, CITY, REGION) -->
            <div class="info-grid cols-4" style="margin-bottom:18px;">
                <?php textField('street', 'House No. / Street / Purok', $row); ?>
                <?php textField('barangay', 'Barangay', $row); ?>
                <?php textField('city', 'Lungsod / City', $row); ?>

                <div class="info-item">
                    <label>Rehiyon / Region</label>
                    <div class="filled-value">NCR</div>
                </div>
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

        <!-- ── FAMILY COMPOSITION ── -->
        <div class="section-title slate">
            <i class="fas fa-users"></i>
            Komposisyon ng Pamilya / Family Composition
        </div>

        <div class="info-body" style="padding-top:16px; padding-bottom:16px;">
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
                                <td><?php echo e($fam['name'] ?? ''); ?></td>
                                <td><?php echo e($fam['relation'] ?? ''); ?></td>
                                <td><?php echo e($fam['age'] ?? ''); ?></td>
                                <td><?php echo e($fam['occupation'] ?? ''); ?></td>
                                <td><?php echo !empty($fam['salary']) ? '₱ ' . number_format((float)$fam['salary'], 2) : '₱ 0.00'; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" style="text-align:center; color:var(--muted); font-style:italic;">Walang naitalang miyembro ng pamilya. / No family members recorded.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <hr class="hr">

        <!-- ── PROBLEM PRESENTED ── -->
        <div class="section-title slate">
            <i class="fas fa-exclamation-circle"></i>
            PROBLEM PRESENTED
        </div>

        <div class="info-body" style="padding-top:16px; padding-bottom:16px;">
            <div style="border: 1.5px dashed var(--border); border-radius:8px; padding:16px; min-height:80px; background:#fafbff;">
            </div>
        </div>

        <hr class="hr">

        <!-- ── SOCIAL WORKER'S ASSESSMENT ── -->
        <div class="section-title">
            <i class="fas fa-clipboard-check"></i>
            Social Worker's Assessment
            <span class="sub">— Para sa DSWD Personnel</span>
        </div>
        <div class="info-body">
            <div style="border: 1.5px dashed var(--border); border-radius:8px; padding:16px; min-height:100px; background:#fafbff;">
            </div>
        </div>

        <div class="info-body" style="padding-top:0; padding-bottom:16px;">
            <table class="family-table">
                <thead>
                    <tr>
                        <th style="width:8%; text-align:center;">#</th>
                        <th style="width:52%;">Purpose of Assistance</th>
                        <th style="width:25%;">Amount</th>
                        <th style="width:15%;">Fund Source</th>
                    </tr>
                </thead>
                <tbody>
                    <?php for ($i = 1; $i <= 3; $i++): 
                        $raw_amount = str_replace(['₱', ''], '', $row['amount_' . $i] ?? '');
                        $clean_amount = trim($raw_amount);
                    ?>
                    <tr>
                        <td style="text-align:center;"><?php echo $i; ?></td>
                        <td>
                            <span class="view-value"><?php echo e($row['purpose_' . $i] ?? ''); ?></span>
                            <input class="edit-input" type="text" name="purpose_<?php echo $i; ?>" value="<?php echo e($row['purpose_' . $i] ?? ''); ?>">
                        </td>
                        <td>
                            <span class="view-value"><?php echo (!empty($clean_amount)) ? '₱ ' . e($clean_amount) : ''; ?></span>
                            <div style="display: flex; align-items: center;" class="edit-input">
                                <span style="padding: 5px 8px; background: #eee; border: 1px solid var(--navy); border-right: none; border-top-left-radius: 4px; border-bottom-left-radius: 4px; font-weight: 600;">₱</span>
                                <input type="text" name="amount_<?php echo $i; ?>" value="<?php echo e($clean_amount); ?>" style="border: 1px solid var(--navy); border-top-right-radius: 4px; border-bottom-right-radius: 4px; padding: 5px 7px; width: 100%; font-family: 'Inter', sans-serif; font-size: 12px; font-weight: 600;">
                            </div>
                        </td>
                        <td>
                            <span class="view-value"><?php echo e($row['fund_source_' . $i] ?? ''); ?></span>
                            <input class="edit-input" type="text" name="fund_source_<?php echo $i; ?>" value="<?php echo e($row['fund_source_' . $i] ?? ''); ?>">
                        </td>
                    </tr>
                    <?php endfor; ?>
                </tbody>
            </table>
        </div>

        <!-- SIGNATURE AREA -->
        <div class="sig-area">
            <div class="sig-box">
                <div class="sig-line"></div>
                <div style="font-size:11px;font-weight:700;color:var(--text);"><?php echo e($fullname); ?></div>
                <div class="sig-label">Buong Pangalan at Pirma ng Kliyente<br>(Signature over Printed Name)</div>
            </div>
            <div class="sig-box">
                <div class="sig-line"></div>
                <div style="font-size:11px;font-weight:700;color:var(--text);">&nbsp;</div>
                <div style="font-size:11px;font-weight:700;color:var(--text);">&nbsp;</div>
                <div class="sig-label">Interviewed by &nbsp;/&nbsp; Reviewed & Approved by<br>(Social Worker)</div>
            </div>
        </div>

        <!-- FOOTER -->
        <div class="doc-footer">
            <span>DSWD Field Office NCR &nbsp;|&nbsp; Batasan Hills Branch &nbsp;|&nbsp; Quezon City</span>
            <span class="gold">AICS — Online Application System</span>
        </div>

    </div>
</form>

<script>
function enableEdit() {
    document.body.classList.add('edit-mode');
    document.getElementById('editBtn').style.display = 'none';
    document.getElementById('saveBtn').style.display = 'inline-flex';
    document.getElementById('cancelBtn').style.display = 'inline-flex';
}

function cancelEdit() {
    document.body.classList.remove('edit-mode');
    document.getElementById('editBtn').style.display = 'inline-flex';
    document.getElementById('saveBtn').style.display = 'none';
    document.getElementById('cancelBtn').style.display = 'none';
}

window.addEventListener('DOMContentLoaded', () => {
    cancelEdit();
});
</script>

</body>
</html>
<?php $conn->close(); ?>