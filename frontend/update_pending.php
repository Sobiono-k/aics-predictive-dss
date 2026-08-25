<?php
// update_pending.php
require_once 'auth.php';

require_once 'db.php';

$id   = (int)($_POST['id'] ?? 0);
$data = [
    'fname'            => $conn->real_escape_string($_POST['fname']   ?? ''),
    'mname'            => $conn->real_escape_string($_POST['mname']   ?? ''),
    'lname'            => $conn->real_escape_string($_POST['lname']   ?? ''),
    'birth_date'       => $conn->real_escape_string($_POST['birth_date'] ?? ''),
    'sex'              => $conn->real_escape_string($_POST['sex']     ?? ''),
    'civil_status'     => $conn->real_escape_string($_POST['civil_status'] ?? ''),
    'barangay'         => $conn->real_escape_string($_POST['barangay'] ?? ''),
    'medical_cause'    => $conn->real_escape_string($_POST['medical_cause'] ?? ''),
    'assistance_type'  => $conn->real_escape_string($_POST['assistance_type'] ?? ''),
    'client_category'  => $conn->real_escape_string($_POST['client_category'] ?? ''),
    'client_subcategory'=> $conn->real_escape_string($_POST['client_subcategory'] ?? ''),
];

$conn->query("UPDATE pending_applications SET
    fname='{$data['fname']}', mname='{$data['mname']}', lname='{$data['lname']}',
    birth_date='{$data['birth_date']}', sex='{$data['sex']}',
    civil_status='{$data['civil_status']}', barangay='{$data['barangay']}',
    medical_cause='{$data['medical_cause']}', assistance_type='{$data['assistance_type']}',
    client_category='{$data['client_category']}', client_subcategory='{$data['client_subcategory']}'
    WHERE id=$id AND is_claimed=0");

echo json_encode(['ok' => $conn->affected_rows >= 0]);
$conn->close();