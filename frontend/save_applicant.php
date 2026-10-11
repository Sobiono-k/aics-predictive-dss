<?php
// save_applicant.php

require_once(__DIR__ . '/../db.php');

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // 1. Collect and Sanitize Fields
    $date           = $conn->real_escape_string($_POST['date']);
    $firstName      = $conn->real_escape_string($_POST['fname']);
    $middleName     = isset($_POST['mname']) ? $conn->real_escape_string($_POST['mname']) : '';
    $lastName       = $conn->real_escape_string($_POST['lname']);
    $sex            = $conn->real_escape_string($_POST['sex']);
    $civilStatus    = isset($_POST['civil_status']) ? $conn->real_escape_string($_POST['civil_status']) : 'Not Specified';

    $birthDate      = !empty($_POST['dob']) ? $conn->real_escape_string($_POST['dob']) : '0000-00-00';

    $barangay       = isset($_POST['barangay']) ? $conn->real_escape_string($_POST['barangay']) : 'Not Specified';
    $medicalCause   = $conn->real_escape_string($_POST['medical_cause']);
    $assistanceType = isset($_POST['assistance_type']) ? $conn->real_escape_string($_POST['assistance_type']) : 'Not Specified';
    $clientCategory    = isset($_POST['client_category']) ? $conn->real_escape_string($_POST['client_category']) : 'Not Specified';

    // ── Client Subcategory: use the custom typed text when "Others specify" was chosen ──
    $clientSubcategoryRaw = isset($_POST['client_subcategory']) ? $_POST['client_subcategory'] : 'Not Specified';
    if ($clientSubcategoryRaw === 'Others specify' && !empty($_POST['client_subcategory_other'])) {
        $clientSubcategory = $conn->real_escape_string(trim($_POST['client_subcategory_other']));
    } else {
        $clientSubcategory = $conn->real_escape_string($clientSubcategoryRaw);
    }

    // ── Email: validate server-side too (never trust client-side validation alone) ──
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

        // Update id_number format (e.g., QC-0001)dds
        $formattedID = "QC-" . $newID; 
        $conn->query("UPDATE aics_sample_data SET id_number = '$formattedID' WHERE id = $newID");

        header("Location: records.php?msg=success&new_id=" . $newID);
        exit();
    } else {
        die("Error processing request: " . $conn->error);
    }
}
?>   