
<?php
// save_applicant.php
ob_start();
date_default_timezone_set('Asia/Manila');

require_once(__DIR__ . '/../db.php');

if ($conn->connect_error) {
    die("Database connection failed.");
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: new_applicant.php");
    exit();
}

// ------------------------------------------
// SANITIZE INPUT
// ------------------------------------------
function clean($value): string {
    return trim((string)($value ?? ''));
}

$date = clean($_POST['date'] ?? date('Y-m-d'));

$firstName  = clean($_POST['fname'] ?? '');
$middleName = clean($_POST['mname'] ?? '');
$lastName   = clean($_POST['lname'] ?? '');

$birthDate = clean($_POST['dob'] ?? '');
$age       = clean($_POST['age'] ?? '');

$sex         = clean($_POST['sex'] ?? '');
$civilStatus = clean($_POST['civil_status'] ?? 'Not Specified');

$barangay = clean($_POST['barangay'] ?? 'Not Specified');
$city     = clean($_POST['city'] ?? '');
$street = clean($_POST['street'] ?? '');

$assistanceType = clean($_POST['assistance_type'] ?? 'Not Specified');
$clientCategory = clean($_POST['client_category'] ?? 'Not Specified');

$clientSubcategoryRaw = clean($_POST['client_subcategory'] ?? '');

if (
    $clientSubcategoryRaw === 'Others specify' &&
    !empty($_POST['client_subcategory_other'])
) {
    $medicalCause = clean($_POST['client_subcategory_other']);
} else {
    $medicalCause = $clientSubcategoryRaw;
}

$clientSubcategory = $medicalCause;

// ------------------------------------------
// EMAIL VALIDATION
// ------------------------------------------
$email = clean($_POST['email'] ?? '');

if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    die("Invalid email address.");
}

// ------------------------------------------
// CONTACT NUMBER VALIDATION
// ------------------------------------------
$contact = preg_replace('/\D+/', '', clean($_POST['contact'] ?? ''));

if ($contact !== '' && strlen($contact) !== 11) {
    die("Contact number must be exactly 11 digits.");
}

// ------------------------------------------
// FAMILY COMPOSITION
// Expected form names:
// family_name[]
// family_relation[]
// family_age[]
// family_occupation[]
// family_salary[]
// ------------------------------------------
$familyNames      = $_POST['family_name'] ?? [];
$familyRelations  = $_POST['family_relation'] ?? [];
$familyAges       = $_POST['family_age'] ?? [];
$familyOccupations = $_POST['family_occupation'] ?? [];
$familySalaries   = $_POST['family_salary'] ?? [];

$family = [];

$totalMembers = max(
    count($familyNames),
    count($familyRelations),
    count($familyAges),
    count($familyOccupations),
    count($familySalaries)
);

for ($i = 0; $i < $totalMembers; $i++) {

    $name = clean($familyNames[$i] ?? '');
    $relation = clean($familyRelations[$i] ?? '');
    $memberAge = clean($familyAges[$i] ?? '');
    $occupation = clean($familyOccupations[$i] ?? '');
    $salary = clean($familySalaries[$i] ?? '');

    // Ignore completely empty household entries.
    if (
        $name === '' &&
        $relation === '' &&
        $memberAge === '' &&
        $occupation === '' &&
        $salary === ''
    ) {
        continue;
    }

    $family[] = [
        'name'       => $name,
        'relation'   => $relation,
        'age'        => $memberAge,
        'occupation' => $occupation,
        'salary'     => $salary
    ];
}

$familyComposition = json_encode(
    $family,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);

if ($familyComposition === false) {
    die("Unable to process family composition.");
}

// ------------------------------------------
// CHECK DATABASE COLUMNS
// ------------------------------------------
$availableColumns = [];

$result = $conn->query("SHOW COLUMNS FROM aics_sample_data");

if (!$result) {
    die("Unable to inspect database table: " . $conn->error);
}

while ($column = $result->fetch_assoc()) {
    $availableColumns[] = $column['Field'];
}

$requiredColumns = [
    'city',
    'family_composition'
];

foreach ($requiredColumns as $requiredColumn) {
    if (!in_array($requiredColumn, $availableColumns, true)) {
        die(
            "Missing database column: " . htmlspecialchars($requiredColumn) .
            ". Please run the database update SQL first."
        );
    }
}

// ------------------------------------------
// PREPARE DATABASE DATA
// Only insert fields that exist in the table.
// ------------------------------------------
$data = [
    'request_date'       => $date,
    'medical_cause'      => $medicalCause,
    'assistance_type'    => $assistanceType,
    'status'             => 'Pending',

    'fname'              => $firstName,
    'mname'              => $middleName,
    'lname'              => $lastName,

    'birth_date'         => $birthDate,
    'age'                => $age,
    'sex'                => $sex,
    'civil_status'       => $civilStatus,

    'barangay'           => $barangay,
    'city'               => $city,
    'street'            => $street,

    'client_category'    => $clientCategory,
    'client_subcategory' => $clientSubcategory,

    'email'              => $email,
    'contact_number'     => $contact,

    'family_composition' => $familyComposition
];

// Keep only columns that actually exist.
$insertData = [];

foreach ($data as $column => $value) {
    if (in_array($column, $availableColumns, true)) {
        $insertData[$column] = $value;
    }
}

// ------------------------------------------
// INSERT APPLICANT
// ------------------------------------------
$columns = array_keys($insertData);

$columnSQL = '`' . implode('`, `', $columns) . '`';
$placeholders = implode(', ', array_fill(0, count($columns), '?'));

$sql = "INSERT INTO aics_sample_data ($columnSQL)
        VALUES ($placeholders)";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    die("Unable to prepare applicant registration: " . $conn->error);
}

$values = array_values($insertData);
$types = str_repeat('s', count($values));

$stmt->bind_param($types, ...$values);

if (!$stmt->execute()) {
    die("Unable to save applicant: " . $stmt->error);
}

$newID = $conn->insert_id;
$stmt->close();

// ------------------------------------------
// GENERATE APPLICATION REFERENCE CODE
// ------------------------------------------
$appCode = "AICS-" . date('Y') . "-" . str_pad($newID, 4, '0', STR_PAD_LEFT);
$formattedID = "QC-" . $newID;

$update = $conn->prepare("
    UPDATE aics_sample_data
    SET id_number = ?, application_code = ?
    WHERE id = ?
");

if ($update) {
    $update->bind_param("ssi", $formattedID, $appCode, $newID);
    $update->execute();
    $update->close();
}

// ------------------------------------------
// SUCCESS
// ------------------------------------------
ob_end_clean();
header("Location: records.php?msg=success&new_id=" . $newID);
exit();
?>
