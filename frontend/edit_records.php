<?php
// edit_records.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
// This grabs the role you saved when the user logged in
$current_role = $_SESSION['role'];

// 1. Database Configuration
require_once(__DIR__ . '/../db.php');

// --- AUDIT TRAIL HELPER FUNCTION ---
function logChange($conn, $record_id, $column, $old_val, $new_val) {
    // Only log if the value actually changed
    if ($old_val != $new_val) {
        $stmt = $conn->prepare("INSERT INTO audit_logs (record_id, action_type, changed_column, old_value, new_value) VALUES (?, 'UPDATE', ?, ?, ?)");
        $stmt->bind_param("isss", $record_id, $column, $old_val, $new_val);
        $stmt->execute();
    }
}

// --- ACTION HANDLER ---
if (isset($_GET['action']) && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    
    if ($_GET['action'] === 'delete') {
    if ($current_role === 'Admin') { // Only allow Admin to delete
        $conn->query("INSERT INTO audit_logs (record_id, action_type, changed_column) VALUES ($id, 'DELETE', 'all_record')");
        $conn->query("DELETE FROM aics_sample_data WHERE id = $id");
    } elseif ($_GET['action'] === 'approve') {
        // Get old status for the log
        $res = $conn->query("SELECT status FROM aics_sample_data WHERE id = $id");
        $old_data = $res->fetch_assoc();
        
        $conn->query("UPDATE aics_sample_data SET status = 'Approved' WHERE id = $id");
        
        // Log the change
        logChange($conn, $id, 'status', $old_data['status'], 'Approved');
    }
    header("Location: records.php?msg=success");
    exit();
}

if (isset($_POST['update_action']) && $_POST['update_action'] === 'update_record') {
    $id = (int)$_POST['edit_id'];
    $cause = $conn->real_escape_string($_POST['medical_cause']);
    $type = $conn->real_escape_string($_POST['assistance_type']);
    $status = $conn->real_escape_string($_POST['status']);
    $date = $conn->real_escape_string($_POST['request_date']);
    
    // 1. Fetch current (OLD) data before we overwrite it
    $res = $conn->query("SELECT medical_cause, assistance_type, status, request_date FROM aics_sample_data WHERE id = $id");
    $old = $res->fetch_assoc();
    
    // 2. Perform the actual update
    $conn->query("UPDATE aics_sample_data SET medical_cause='$cause', assistance_type='$type', status='$status', request_date='$date' WHERE id=$id");
    
    // 3. Log each field if it was changed
    logChange($conn, $id, 'medical_cause', $old['medical_cause'], $cause);
    logChange($conn, $id, 'assistance_type', $old['assistance_type'], $type);
    logChange($conn, $id, 'status', $old['status'], $status);
    logChange($conn, $id, 'request_date', $old['request_date'], $date);

    header("Location: records.php?msg=updated");
    exit();
}
}