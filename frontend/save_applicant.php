<?php
// save_applicant.php

require_once(__DIR__ . '/../db.php');

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // 1. Collect and Sanitize Fields
    $date             = isset($_POST['date']) ? $conn->real_escape_string($_POST['date']) : '';
    $firstName        = isset($_POST['fname']) ? $conn->real_escape_string($_POST['fname']) : '';
    $middleName       = isset($_POST['mname']) ? $conn->real_escape_string($_POST['mname']) : '';
    $lastName         = isset($_POST['lname']) ? $conn->real_escape_string($_POST['lname']) : '';
    $sex              = isset($_POST['sex']) ? $conn->real_escape_string($_POST['sex']) : '';
    $civilStatus      = isset($_POST['civil_status']) ? $conn->real_escape_string($_POST['civil_status']) : 'Not Specified';

    $birthDate        = !empty($_POST['dob']) ? $conn->real_escape_string($_POST['dob']) : '0000-00-00';

    $barangay         = isset($_POST['barangay']) ? $conn->real_escape_string($_POST['barangay']) : 'Not Specified';
    $assistanceType   = isset($_POST['assistance_type']) ? $conn->real_escape_string($_POST['assistance_type']) : 'Not Specified';
    $clientCategory   = isset($_POST['client_category']) ? $conn->real_escape_string($_POST['client_category']) : 'Not Specified';

    // ── Kunin ang Subcategory at direktang ilagay sa Medical Cause ──
    $clientSubcategoryRaw = isset($_POST['client_subcategory']) ? $_POST['client_subcategory'] : '';
    if ($clientSubcategoryRaw === 'Others specify' && !empty($_POST['client_subcategory_other'])) {
        $medicalCause = $conn->real_escape_string(trim($_POST['client_subcategory_other']));
    } else {
        $medicalCause = $conn->real_escape_string($clientSubcategoryRaw);
    }
    
    // Pwede rin nating panatilihing pareho o i-set ang client_subcategory kung kailangan sa ibang column
    $clientSubcategory = $medicalCause; 

    // ── Email: validate server-side too ──
    $emailRaw = isset($_POST['email']) ? trim($_POST['email']) : '';
    if ($emailRaw !== '' && !filter_var($emailRaw, FILTER_VALIDATE_EMAIL)) {
        die("Invalid email address format.");
    }
    $email = $conn->real_escape_string($emailRaw);

    // ── Contact Number: strip non-digits, validate length server-side ──
    $contactRaw = isset($_POST['contact']) ? preg_replace('/\D/', '', $_POST['contact']) : '';
    if ($contactRaw !== '' && strlen($contactRaw) !== 11) {
        die("Contact number must be exactly 11 digits.");
    }
    $contact = $conn->real_escape_string($contactRaw);

    // 2. Query execution incorporating civil_status, email, and contact
    $sql = "INSERT INTO aics_sample_data (
                request_date, 
                medical_cause, 
                assistance_type, 
                status, 
                fname, 
                mname, 
                lname, 
                birth_date, 
                sex,
                civil_status,
                barangay,
                client_category,
                client_subcategory,
                email,
                contact_number
            ) 
            VALUES (
                '$date', 
                '$medicalCause', 
                '$assistanceType', 
                'Pending', 
                '$firstName', 
                '$middleName', 
                '$lastName', 
                '$birthDate', 
                '$sex',
                '$civilStatus',
                '$barangay',
                '$clientCategory',
                '$clientSubcategory',
                '$email',
                '$contact'
            )";

    if ($conn->query($sql) === TRUE) {
        $newID = $conn->insert_id;

        // Mag-generate ng Application Code / Reference Code (halimbawa: AICS-2026-0001)
        $appCode = "AICS-" . date('Y') . "-" . str_pad($newID, 4, '0', STR_PAD_LEFT);
        $formattedID = "QC-" . $newID; 

        // I-save pareho ang id_number at application_code sa database
        $conn->query("UPDATE aics_sample_data SET id_number = '$formattedID', application_code = '$appCode' WHERE id = $newID");

        header("Location: records.php?msg=success&new_id=" . $newID);
        exit();
    }
}
?>