<?php
// submit_public_form.php
// Receives public_form.php submission, saves to pending_applications, shows reference code
require_once __DIR__ . '/db.php';

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: public_form.php");
    exit();
}

// ── Collect, Sanitize, and Force UPPERCASE ──
$fname       = strtoupper($conn->real_escape_string(trim($_POST['fname'] ?? '')));
$mname       = strtoupper($conn->real_escape_string(trim($_POST['mname'] ?? '')));
$lname       = strtoupper($conn->real_escape_string(trim($_POST['lname'] ?? '')));
$dob         = $conn->real_escape_string(trim($_POST['dob'] ?? ''));
$sex         = strtoupper($conn->real_escape_string(trim($_POST['sex'] ?? '')));
$civil       = strtoupper($conn->real_escape_string(trim($_POST['civil_status'] ?? 'NOT SPECIFIED')));
$barangay    = strtoupper($conn->real_escape_string(trim($_POST['barangay'] ?? '')));
$street      = strtoupper($conn->real_escape_string(trim($_POST['street'] ?? '')));
$mobile      = $conn->real_escape_string(trim($_POST['cp_number'] ?? ''));
$occupation  = strtoupper($conn->real_escape_string(trim($_POST['occupation'] ?? '')));

// Checkbox fields processed by JS into fields
$medical_cause   = strtoupper($conn->real_escape_string(trim($_POST['medical_cause'] ?? '')));
$assistance_type = strtoupper($conn->real_escape_string(trim($_POST['assistance_type'] ?? '')));
$client_cat      = strtoupper($conn->real_escape_string(trim($_POST['client_category'] ?? '')));
$client_subcat   = strtoupper($conn->real_escape_string(trim($_POST['client_subcategory'] ?? '')));

// Family composition — encode as JSON and Force UPPERCASE inside data array
$family_data = [];
$names    = $_POST['family_name']       ?? [];
$rels     = $_POST['family_relation']   ?? [];
$ages     = $_POST['family_age']        ?? [];
$occs     = $_POST['family_occupation'] ?? [];
$salaries = $_POST['family_salary']     ?? [];

foreach ($names as $i => $name) {
    if (trim($name) !== '') {
        $family_data[] = [
            'name'       => strtoupper(trim($name)),
            'relation'   => strtoupper(trim($rels[$i] ?? '')),
            'age'        => trim($ages[$i] ?? ''),
            'occupation' => strtoupper(trim($occs[$i] ?? '')),
            'salary'     => trim($salaries[$i] ?? '0'),
        ];
    }
}
$family_json = $conn->real_escape_string(json_encode($family_data));

// Validate required fields server-side
$errors = [];
if (!$fname)           $errors[] = "First name is required.";
if (!$lname)           $errors[] = "Last name is required.";
if (!$dob)             $errors[] = "Birthdate is required.";
if (!$sex)             $errors[] = "Sex is required.";
if (!$civil)           $errors[] = "Civil status is required.";
if (!$barangay)        $errors[] = "Barangay is required.";
if (!$mobile)          $errors[] = "Mobile number is required.";
if (!$medical_cause)   $errors[] = "Medical cause is required.";
if (!$assistance_type) $errors[] = "Type of assistance is required.";
if (!$client_cat)      $errors[] = "Client category is required.";
if (!$client_subcat)   $errors[] = "Client sub-category is required.";

if (!empty($errors)) {
    header("Location: public_form.php?error=" . urlencode(implode(' | ', $errors)));
    exit();
}

// Generate unique application code: AICS-YYYYMMDD-XXXX
$code = "AICS-" . date("Ymd") . "-" . strtoupper(substr(uniqid(), -4));

// ── Duplicate guard: dynamic data checking routing ──
$status = "PENDING"; // Default application routing state

$dup_check = $conn->query("
    SELECT id FROM pending_applications
    WHERE is_claimed = 0
      AND lname = '$lname'
      AND fname = '$fname'
      AND birth_date = '$dob'
    LIMIT 1
");

$phone_check = $conn->query("
    SELECT id FROM pending_applications
    WHERE is_claimed = 0
      AND cp_number = '$mobile'
    LIMIT 1
");

// Kung may nahanap na katulad na Record, ipasok pa rin ngunit baguhin ang katayuan bilang Duplicate Pending
if (($dup_check && $dup_check->num_rows > 0) || ($phone_check && $phone_check->num_rows > 0)) {
    $status = "DUPLICATE PENDING";
}

$sql = "INSERT INTO pending_applications 
    (application_code, fname, mname, lname, birth_date, sex, civil_status,
     barangay, street, cp_number, occupation, medical_cause, assistance_type, client_category, client_subcategory, family_composition, status)
    VALUES 
    ('$code', '$fname', '$mname', '$lname', '$dob', '$sex', '$civil',
     '$barangay', '$street', '$mobile', '$occupation', '$medical_cause', '$assistance_type', '$client_cat', '$client_subcat', '$family_json', '$status')";

if (!$conn->query($sql)) {
    die("Error saving application: " . $conn->error);
}

$conn->close();

$fullname = "$fname " . ($mname ? "$mname " : "") . $lname;
$today    = date("F d, Y");
?>
<!DOCTYPE html>
<html lang="fil">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Application Submitted — DSWD AICS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', sans-serif; background: #eef2f7; min-height: 100vh; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 24px; }
        .card { background: #fff; max-width: 500px; width: 100%; border-radius: 14px; overflow: hidden; box-shadow: 0 8px 30px rgba(0,0,0,.12); }
        .card-top { background: #003893; padding: 28px 32px 22px; text-align: center; color: #fff; }
        .card-top .check-icon { width: 64px; height: 64px; background: #10b981; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 28px; margin: 0 auto 14px; box-shadow: 0 0 0 8px rgba(16,185,129,.2); }
        .card-top h2 { font-size: 20px; font-weight: 800; }
        .card-top p  { font-size: 12px; opacity: .75; margin-top: 4px; }
        .accent-bar { height: 4px; background: repeating-linear-gradient(90deg, #c8a94a 0, #c8a94a 20px, #ce1126 20px, #ce1126 40px); }
        .card-body { padding: 28px 32px; }
        .name-row { font-size: 15px; font-weight: 700; color: #1e293b; text-align: center; margin-bottom: 22px; }
        .code-box { background: #f0f4ff; border: 2px solid #003893; border-radius: 10px; padding: 18px; text-align: center; margin-bottom: 22px; }
        .code-label { font-size: 10px; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 8px; }
        .code-value { font-family: 'Courier New', monospace; font-size: 26px; font-weight: 800; color: #003893; letter-spacing: 3px; }
        .code-date { font-size: 11px; color: #94a3b8; margin-top: 6px; }
        .instruction { background: #fef9ec; border: 1px solid #f5c842; border-radius: 8px; padding: 14px 16px; font-size: 12px; color: #7c5c00; line-height: 1.7; margin-bottom: 22px; display: flex; gap: 10px; }
        .instruction i { font-size: 16px; flex-shrink: 0; margin-top: 2px; color: #d97706; }
        .steps-list { margin-bottom: 22px; }
        .steps-list li { display: flex; align-items: flex-start; gap: 12px; font-size: 13px; color: #334155; padding: 8px 0; border-bottom: 1px solid #f1f5f9; }
        .steps-list li:last-child { border-bottom: none; }
        .step-num { width: 24px; height: 24px; background: #003893; color: #fff; border-radius: 50%; font-size: 11px; font-weight: 800; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .btn-print { width: 100%; padding: 14px; background: #003893; color: #fff; border: none; border-radius: 8px; font-size: 14px; font-weight: 700; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 10px; transition: .2s; }
        .btn-print:hover { background: #002a6d; }
        
        /* Alert notification context style if system marked as duplicate */
        .alert-duplicate { background: #fff7ed; color: #c2410c; border: 1px solid #fed7aa; border-radius: 8px; padding: 12px; font-size: 12px; margin-bottom: 15px; }

        @media print { body { background: #fff; } .btn-print, .instruction { display: none; } }
    </style>
</head>
<body>

<div class="card">
    <div class="card-top">
        <div class="check-icon"><i class="fas fa-check"></i></div>
        <h2>Application Submitted!</h2>
        <p>Matagumpay na natanggap ang inyong aplikasyon</p>
    </div>
    <div class="accent-bar"></div>

    <div class="card-body">

        <?php if ($status === "DUPLICATE PENDING"): ?>
            <div class="alert-duplicate">
                <i class="fas fa-info-circle"></i> <strong>Paunawa:</strong> Ang system ay nakakita ng kahawig na pangalan o numero. Ang iyong aplikasyon ay dadaan sa masusing manual review ng Admin.
            </div>
        <?php endif; ?>

        <div class="name-row">
            <i class="fas fa-user-circle" style="color:#003893; margin-right:6px;"></i>
            <?php echo htmlspecialchars($fullname); ?>
        </div>

        <div class="code-box">
            <div class="code-label">Inyong Reference Code</div>
            <div class="code-value"><?php echo htmlspecialchars($code); ?></div>
            <div class="code-date">Submitted: <?php echo $today; ?></div>
        </div>

        <div class="instruction">
            <i class="fas fa-exclamation-circle"></i>
            <div>
                <strong>MAHALAGA:</strong> I-screenshot o i-print ang code na ito.
                Dalhin sa DSWD counter at ipakita sa staff para ma-process ang inyong aplikasyon.
            </div>
        </div>

        <ul class="steps-list">
            <li>
                <div class="step-num">1</div>
                <span>I-screenshot o i-print ang reference code na ito</span>
            </li>
            <li>
                <div class="step-num">2</div>
                <span>Pumunta sa pinakamalapit na <strong>DSWD AICS Processing Unit</strong></span>
            </li>
            <li>
                <div class="step-num">3</div>
                <span>Ipakita ang code sa staff para i-verify ang inyong impormasyon</span>
            </li>
        </ul>

        <button class="btn-print" onclick="window.print()">
            <i class="fas fa-print"></i> I-print ang Reference Code
        </button>

    </div>
</div>

</body>
</html>