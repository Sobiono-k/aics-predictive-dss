<?php
// records.php

session_start();

if (!isset($_SESSION['role'])) {
    header("Location: login.php");
    exit();
}

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'auth.php';

// 1. Database Configuration
require_once __DIR__ . '/db.php';

// --- ACTIVE FILTERS RESOLUTION ---
$search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';
$cause_filter = isset($_GET['cause']) ? $conn->real_escape_string($_GET['cause']) : '';
$type_filter = isset($_GET['type_filter']) ? $conn->real_escape_string($_GET['type_filter']) : '';
$status_filter = isset($_GET['status_filter']) ? $conn->real_escape_string($_GET['status_filter']) : '';
$start_date = isset($_GET['start']) ? $conn->real_escape_string($_GET['start']) : '';
$end_date = isset($_GET['end']) ? $conn->real_escape_string($_GET['end']) : '';

// Build reusable validation condition mapping strings
$where_clauses = ["1=1"];
$is_duplicate_view = (isset($_GET['action']) && $_GET['action'] === 'find_duplicates');

if ($is_duplicate_view) {
    $where_clauses[] = "CONCAT(fname, lname, birth_date) IN (
        SELECT CONCAT(fname, lname, birth_date) 
        FROM aics_sample_data 
        GROUP BY fname, lname, birth_date 
        HAVING COUNT(*) > 1
    )";
    $sort_logic = "lname ASC, fname ASC, request_date DESC";
} else {
    $sort_logic = "request_date DESC, id DESC";
}

if (!empty($search)) $where_clauses[] = "(medical_cause LIKE '%$search%' OR assistance_type LIKE '%$search%' OR fname LIKE '%$search%' OR lname LIKE '%$search%')";
if (!empty($cause_filter)) $where_clauses[] = "medical_cause = '$cause_filter'";
if (!empty($type_filter)) $where_clauses[] = "assistance_type = '$type_filter'";
if (!empty($status_filter)) $where_clauses[] = "status = '$status_filter'";
if (!empty($start_date)) $where_clauses[] = "request_date >= '$start_date'";
if (!empty($end_date)) $where_clauses[] = "request_date <= '$end_date'";

$where_str = implode(" AND ", $where_clauses);

// --- NATIVE EXCEL STREAM EXPORT INTERCEPTOR ---
if (isset($_GET['action']) && $_GET['action'] === 'export_excel') {
    $filename = "AICS_Beneficiary_Export_" . date('Ymd_His') . ".xls";
    
    // Send standard download headers
    header("Content-Type: application/vnd.ms-excel; charset=utf-8");
    header("Content-Disposition: attachment; filename=\"$filename\"");
    header("Pragma: no-cache");
    header("Expires: 0");

    // Print headers using table layout structure native to Excel mapping engines
    echo "<table border='1'>";
    echo "<tr style='background-color:#2c3e50; color:white; font-weight:bold;'>";
    echo "<th>Date</th>";
    echo "<th>Barangay</th>";
    echo "<th>Sex</th>";
    echo "<th>Civil Status</th>";
    echo "<th>Age</th>";
    echo "<th>Type of Assistance</th>";
    echo "<th>Client Category</th>";
    echo "<th>Sub Category</th>";
    echo "</tr>";

    // Run custom comprehensive filter dump query
    $export_sql = "SELECT request_date, barangay, sex, civil_status, birth_date, assistance_type, client_category, client_subcategory FROM aics_sample_data WHERE $where_str ORDER BY $sort_logic";
    $export_res = $conn->query($export_sql);

    if ($export_res && $export_res->num_rows > 0) {
        while ($row = $export_res->fetch_assoc()) {
            // Dynamic context safe calculation of structural age properties
            $age = 'N/A';
            if (!empty($row['birth_date']) && $row['birth_date'] != '0000-00-00') {
                $bday = new DateTime($row['birth_date']);
                $context_date = new DateTime($row['request_date']);
                $age = $context_date->diff($bday)->y;
            }

            echo "<tr>";
            echo "<td>" . htmlspecialchars($row['request_date']) . "</td>";
            echo "<td>" . htmlspecialchars($row['barangay'] ?? 'N/A') . "</td>";
            echo "<td>" . htmlspecialchars($row['sex'] ?? 'N/A') . "</td>";
            echo "<td>" . htmlspecialchars($row['civil_status'] ?? 'N/A') . "</td>";
            echo "<td>" . $age . "</td>";
            echo "<td>" . htmlspecialchars($row['assistance_type'] ?? 'N/A') . "</td>";
            echo "<td>" . htmlspecialchars($row['client_category'] ?? 'N/A') . "</td>";
            echo "<td>" . htmlspecialchars($row['client_subcategory'] ?? 'N/A') . "</td>";
            echo "</tr>";
        }
    }
    echo "</table>";
    exit();
}

// --- STANDARD SCRIPTS HANDLER ACTIONS ---

function logChange($conn, $record_id, $column, $old_val, $new_val) {
    if ($old_val != $new_val) {
        $stmt = $conn->prepare("INSERT INTO audit_logs (record_id, action_type, changed_column, old_value, new_value) VALUES (?, 'UPDATE', ?, ?, ?)");
        $stmt->bind_param("isss", $record_id, $column, $old_val, $new_val);
        $stmt->execute();
    }
}

function verifyAdminAuth($password, $conn) {
    $stmt = $conn->prepare("SELECT password FROM users WHERE role = 'Admin' LIMIT 1");
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        return ($password === $row['password']);
    }
    return false;
}

// Handle Update



// Handle Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_record'])) {
    if ($_SESSION['role'] === 'Staff') {
        if (!isset($_POST['admin_pass']) || !verifyAdminAuth($_POST['admin_pass'], $conn)) {
            header("Location: records.php?msg=auth_failed");
            exit();
        }
    }
    $id = (int)$_POST['delete_id'];
    $conn->query("INSERT INTO audit_logs (record_id, action_type, changed_column) VALUES ($id, 'DELETE', 'full_record')");
    $conn->query("DELETE FROM aics_sample_data WHERE id = $id");
    header("Location: records.php?msg=success");
    exit();
}

// Handle Approve
if (isset($_GET['action']) && $_GET['action'] === 'approve' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $res = $conn->query("SELECT status FROM aics_sample_data WHERE id = $id");
    $old = $res->fetch_assoc();
    $conn->query("UPDATE aics_sample_data SET status='Approved' WHERE id=$id");
    logChange($conn, $id, 'status', $old['status'], 'Approved');
    header("Location: records.php?msg=success");
    exit();
}

// --- CSV IMPORT HANDLER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_csv'])) {
    $import_errors = [];
    $import_success = 0;
    $import_skipped = 0;

    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        header("Location: records.php?msg=csv_upload_error");
        exit();
    }

    $file = $_FILES['csv_file']['tmp_name'];
    $handle = fopen($file, 'r');

    if ($handle === false) {
        header("Location: records.php?msg=csv_upload_error");
        exit();
    }

    // Read header row
    $headers = fgetcsv($handle);
    if (!$headers) {
        fclose($handle);
        header("Location: records.php?msg=csv_empty");
        exit();
    }

    // Normalize headers: lowercase + trim
    $headers = array_map(fn($h) => strtolower(trim($h)), $headers);

    // Column mapping: CSV header → DB column
    $column_map = [
        'id_number'          => 'id_number',
        'id number'          => 'id_number',
        'request_date'       => 'request_date',
        'request date'       => 'request_date',
        'date'               => 'request_date',
        'fname'              => 'fname',
        'first name'         => 'fname',
        'first_name'         => 'fname',
        'mname'              => 'mname',
        'middle name'        => 'mname',
        'middle_name'        => 'mname',
        'lname'              => 'lname',
        'last name'          => 'lname',
        'last_name'          => 'lname',
        'barangay'           => 'barangay',
        'brgy'               => 'barangay',
        'birth_date'         => 'birth_date',
        'birthdate'          => 'birth_date',
        'birth date'         => 'birth_date',
        'sex'                => 'sex',
        'gender'             => 'sex',
        'civil_status'       => 'civil_status',
        'civil status'       => 'civil_status',
        'medical_cause'      => 'medical_cause',
        'medical cause'      => 'medical_cause',
        'diagnosis'          => 'medical_cause',
        'assistance_type'    => 'assistance_type',
        'assistance type'    => 'assistance_type',
        'type of assistance' => 'assistance_type',
        'client_category'    => 'client_category',
        'client category'    => 'client_category',
        'category'           => 'client_category',
        'client_subcategory' => 'client_subcategory',
        'client subcategory' => 'client_subcategory',
        'subcategory'        => 'client_subcategory',
        'status'             => 'status',
        'remarks'            => 'remarks',
        'notes'              => 'remarks',
    ];

    // Map CSV column indices to DB columns
    $col_index = [];
    foreach ($headers as $i => $h) {
        if (isset($column_map[$h])) {
            $col_index[$column_map[$h]] = $i;
        }
    }

    $stmt = $conn->prepare("INSERT INTO aics_sample_data 
        (id_number, request_date, fname, mname, lname, barangay, birth_date, sex, civil_status, medical_cause, assistance_type, client_category, client_subcategory, status, remarks)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    while (($row = fgetcsv($handle)) !== false) {
        // Skip completely empty rows
        if (empty(array_filter($row))) { $import_skipped++; continue; }

        $get = fn($col) => isset($col_index[$col]) ? trim($row[$col_index[$col]] ?? '') : '';

        $id_number    = $get('id_number')       ?: null;
        $request_date = $get('request_date')    ?: date('Y-m-d');
        // Auto-convert common date formats to YYYY-MM-DD
        if ($request_date && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $request_date)) {
            $parsed = date_create($request_date);
            $request_date = $parsed ? date_format($parsed, 'Y-m-d') : date('Y-m-d');
        }
        $fname        = $get('fname')           ?: null;
        $mname        = $get('mname')           ?: null;
        $lname        = $get('lname')           ?: null;
        $barangay     = $get('barangay')        ?: null;
        $birth_date   = $get('birth_date')      ?: null;
        if ($birth_date && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $birth_date)) {
            $parsed = date_create($birth_date);
            $birth_date = $parsed ? date_format($parsed, 'Y-m-d') : null;
        }
        $sex          = $get('sex')             ?: null;
        $civil_status = $get('civil_status')    ?: null;
        $medical_cause= $get('medical_cause')   ?: null;
        $assist_type  = $get('assistance_type') ?: null;
        $client_cat   = $get('client_category') ?: null;
        $client_sub   = $get('client_subcategory') ?: null;
        $status       = $get('status')          ?: 'Pending';
        $remarks      = $get('remarks')         ?: null;

        $stmt->bind_param("sssssssssssssss",
            $id_number, $request_date, $fname, $mname, $lname,
            $barangay, $birth_date, $sex, $civil_status,
            $medical_cause, $assist_type, $client_cat, $client_sub,
            $status, $remarks
        );

        if ($stmt->execute()) {
            $import_success++;
            // Log import in audit trail
            $new_id = $conn->insert_id;
            $conn->query("INSERT INTO audit_logs (record_id, action_type, changed_column, new_value) VALUES ($new_id, 'INSERT', 'csv_import', 'Imported via CSV')");
        } else {
            $import_skipped++;
        }
    }

    fclose($handle);
    $stmt->close();

    header("Location: records.php?msg=csv_imported&imported=$import_success&skipped=$import_skipped");
    exit();
}

// 2. Pagination Configuration
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20; 
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

// 3. FETCH UNIQUE DROPDOWN VALUES
$unique_causes = [];
$res_c = $conn->query("SELECT DISTINCT medical_cause FROM aics_sample_data WHERE medical_cause != '' ORDER BY medical_cause ASC");
while($r = $res_c->fetch_assoc()) $unique_causes[] = $r['medical_cause'];

$unique_types = [];
$res_t = $conn->query("SELECT DISTINCT assistance_type FROM aics_sample_data WHERE assistance_type != '' ORDER BY assistance_type ASC");
while($r = $res_t->fetch_assoc()) $unique_types[] = $r['assistance_type'];

$total_res = $conn->query("SELECT COUNT(*) as total FROM aics_sample_data WHERE $where_str");
$total_records = $total_res->fetch_assoc()['total'];
$total_pages = ceil($total_records / $limit);

$sql = "SELECT id, id_number, request_date, medical_cause, assistance_type, status, fname, mname, lname, barangay, birth_date, remarks 
        FROM aics_sample_data 
        WHERE $where_str 
        ORDER BY $sort_logic 
        LIMIT $offset, $limit";

$result = $conn->query($sql);

if (!$result) {
    die("<div style='background:#fee2e2; color:#b91c1c; padding:20px; border-radius:8px; margin:20px;'>
            <strong>Database Query Error:</strong> " . $conn->error . "<br>
            <em>Check if columns like 'remarks' or 'id_number' actually exist in your table.</em>
         </div>");
}

$all_records = [];
if ($result->num_rows > 0) {
    while($row = $result->fetch_assoc()) { 
        $all_records[] = $row; 
    }
}

function getPaginationUrl($p, $l = null) {
    $params = $_GET;
    $params['page'] = $p;
    if($l) $params['limit'] = $l;
    return "?" . http_build_query($params);
}

// Generate separate dynamic parameter payload for preserving configurations during excel processing
$excel_url = "records.php?" . http_build_query(array_merge($_GET, ['action' => 'export_excel']));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Records - DSWD</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html { overflow-y: scroll; }
        :root { --dswd-dark: #2c3e50; --sidebar-bg: #1e293b; --bg-color: #f8fafc; --card-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); --sidebar-width: 260px; }
        body { font-family: 'Inter', sans-serif; margin: 0; background: var(--bg-color); display: flex; color: #334155; }
        .sidebar { width: var(--sidebar-width); height: 100vh; background: var(--sidebar-bg); position: fixed; left: 0; top: 0; color: #fff; display: flex; flex-direction: column; z-index: 1000; }
        .sidebar-header { padding: 30px 20px; text-align: center; background: rgba(0,0,0,0.2); }
        .sidebar a { padding: 15px 25px; text-decoration: none; color: #94a3b8; display: flex; align-items: center; transition: all 0.3s ease; border-left: 4px solid transparent; }
        .sidebar a:hover, .sidebar a.active { background: rgba(255, 255, 255, 0.05); color: #fff; border-left: 4px solid #3b82f6; }
        .main { margin-left: 260px; padding: 40px; width: calc(100% - 260px); min-height: 100vh; }
        .header-area { margin-bottom: 30px; }
        .header-area h1 { margin: 0; font-size: 24px; color: var(--dswd-dark); }
        .table-container { background: #fff; border-radius: 12px; box-shadow: var(--card-shadow); overflow: hidden; border: 1px solid #e2e8f0; }
        .filter-header { padding: 20px; background: #fff; border-bottom: 1px solid #f1f5f9; }
        .controls-row { display: flex; gap: 15px; align-items: center; flex-wrap: wrap; margin-bottom: 15px; }
        .search-wrapper { position: relative; flex: 2; min-width: 250px; }
        .search-wrapper i { position: absolute; left: 12px; top: 12px; color: #94a3b8; }
        .input-field { padding: 10px 15px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 14px; background: #fff; }
        .search-box { width: 100%; padding-left: 35px; box-sizing: border-box; }
        .filter-select { flex: 1; min-width: 150px; background: #f8fafc; cursor: pointer; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 15px 20px; background: #f8fafc; color: #64748b; font-size: 11px; text-transform: uppercase; border-bottom: 1px solid #e2e8f0; }
        td { padding: 16px 20px; border-bottom: 1px solid #f1f5f9; font-size: 14px; }
        td.no-print {
            white-space: nowrap;
        }

        .action-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .badge { padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; background: #eff6ff; color: #1d4ed8; }
        .status-badge { padding: 5px 12px; border-radius: 6px; font-size: 11px; font-weight: 700; display: inline-block; text-transform: uppercase; }
        .status-pending { background: #fef3c7; color: #d97706; }
        .status-approved { background: #dcfce7; color: #16a34a; }
        .status-paid { background: #dbeafe; color: #2563eb; }
        .status-waitlisted { background: #f3e8ff; color: #9333ea; }
        .status-declined { background: #fee2e2; color: #dc2626; }
        .btn-excel { padding: 10px 20px; background: #0f172a; color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 600; text-decoration: none; display: flex; align-items: center; gap: 8px; transition: 0.2s; }
        .btn-duplicate { padding: 10px 20px; background: #0f172a; color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 600; text-decoration:none; display: flex; align-items: center; gap: 8px; transition: 0.2s; }
        .btn-recent { padding: 10px 20px; background: #f8fafc; color: #1e293b; border: 1px solid #e2e8f0; border-radius: 8px; cursor: pointer; font-weight: 600; text-decoration: none; display: flex; align-items: center; gap: 8px; transition: 0.2s; }
        .action-btn { padding: 6px; border-radius: 4px; border: 1px solid #e2e8f0; color: #64748b; transition: 0.2s; cursor: pointer; text-decoration: none; font-size: 12px; margin-right: 5px; background: #fff; }
        .btn-edit { color: #3b82f6; } .btn-edit:hover { background: #eff6ff; }
        .btn-delete { color: #ef4444; } .btn-delete:hover { background: #fef2f2; }
        .btn-approve { color: #10b981; } .btn-approve:hover { background: #f0fdf4; }
        .btn-view { color: #6366f1; } .btn-view:hover { background: #eef2ff; }
        .total-counter-box { background: #fff; padding: 20px; border-radius: 12px; margin-bottom: 20px; border: 1px solid #e2e8f0; display: flex; align-items: center; gap: 15px; width: 320px; }
        .counter-icon { width: 45px; height: 45px; background: #eff6ff; color: #3b82f6; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 20px; }
        .pagination-footer { padding: 15px 20px; display: flex; justify-content: space-between; align-items: center; background: #f8fafc; border-top: 1px solid #e2e8f0; }
        .pagination-btns { display: flex; gap: 4px; }
        .pg-btn { padding: 6px 12px; border: 1px solid #e2e8f0; background: #fff; border-radius: 6px; text-decoration: none; color: #1e293b; font-size: 13px; font-weight: 600; transition: 0.1s; min-width: 32px; text-align: center; }
        .pg-btn.active { background: #3b82f6; color: #fff; border-color: #3b82f6; }
        .pg-btn.disabled { color: #cbd5e1; pointer-events: none; background: #f8fafc; }
        .modal-overlay, .modal { display: none; position: fixed; z-index: 9999; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); backdrop-filter: blur(4px); }
        .modal-box, .modal-content { background: #fff; margin: 5% auto; padding: 30px; border-radius: 12px; width: 450px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1); border: 1px solid #e2e8f0; position: relative;}
        .modal-input { width: 100%; padding: 10px; border: 1px solid #e2e8f0; border-radius: 8px; margin-top: 5px; box-sizing: border-box; font-family: inherit; }
        .toast-msg { position: fixed; top: 20px; right: 20px; background: #10b981; color: white; padding: 15px 25px; border-radius: 8px; z-index: 10000; font-weight: 600; }
        .close { position: absolute; right: 20px; top: 15px; font-size: 24px; cursor: pointer; color: #94a3b8; }
        @media print {
            .sidebar, .btn-print, .btn-excel, .btn-duplicate, .btn-recent, .filter-header, .pagination-footer, .no-print, .toast-msg { display: none !important; }
            body { background: white; color: black; }
            .main { margin-left: 0 !important; padding: 0 !important; width: 100% !important; }
            .table-container { box-shadow: none !important; border: 1px solid #ccc !important; }
            table { width: 100% !important; }
            th, td { border: 1px solid #eee !important; }
        }
    </style>
</head>
<body>

<?php include 'sidebar.php'; ?>

<div class="main">
    
    <?php if (isset($_GET['msg'])): ?>
    <div id="toast" class="toast-msg" style="<?php echo ($_GET['msg'] == 'auth_failed') ? 'background:#ef4444;' : ''; ?>">
        <i class="fas <?php echo ($_GET['msg'] == 'auth_failed') ? 'fa-shield-alt' : 'fa-check-circle'; ?>"></i>
        <?php 
            if($_GET['msg'] == 'updated') echo "Record updated successfully!";
            elseif($_GET['msg'] == 'success') echo "Action completed!";
            elseif($_GET['msg'] == 'auth_failed') echo "Invalid Admin Credentials!";
            elseif($_GET['msg'] == 'csv_imported') echo "CSV Import complete — " . (int)($_GET['imported'] ?? 0) . " records added" . ((int)($_GET['skipped'] ?? 0) > 0 ? ", " . (int)$_GET['skipped'] . " skipped" : "") . ".";
            elseif($_GET['msg'] == 'csv_upload_error') echo "CSV upload failed. Please try again.";
            elseif($_GET['msg'] == 'csv_empty') echo "The uploaded CSV file appears to be empty.";
        ?>
    </div>
    <script>setTimeout(() => { document.getElementById('toast').style.display = 'none'; }, 3000);</script>
    <?php endif; ?>

    <form id="filterForm" method="GET">
        <input type="hidden" name="limit" value="<?php echo $limit; ?>">

        <div class="header-area" style="display: flex; justify-content: space-between; align-items: flex-end;">
            <div>
                <h1 style="margin:0; color:var(--dswd-dark); font-size: 28px;">Beneficiary Records</h1>
                <p style="color:#64748b; margin-top: 5px;">Historical database of AICS interventions</p>
            </div>
            
            <div style="display: flex; gap: 10px; align-items: center;">
                <a href="records.php?action=find_duplicates" class="btn-duplicate">
                    <i class="fas fa-copy"></i> Find Duplicates
                </a>

                <button type="button" onclick="openImportModal()" class="btn-excel" style="background:#16a34a;">
                    <i class="fas fa-file-csv"></i> Import CSV
                </button>

                <div style="display: flex; gap: 10px;">
                    <a href="<?php echo $excel_url; ?>" class="btn-excel">
                        <i class="fas fa-file-excel"></i> Print Records
                    </a>
                </div>
            </div>
        </div>

        <div style="margin-top: 20px; margin-bottom: 20px;">
            <div class="total-counter-box">
                <div class="counter-icon"><i class="fas fa-users"></i></div>
                <div>
                    <div style="font-size: 12px; color: #94a3b8; text-transform: uppercase; font-weight: 700;">Total Applicants</div>
                    <div style="font-size: 24px; font-weight: 700; color: #1e293b;"><?php echo number_format($total_records); ?></div>
                </div>
            </div>
        </div>

        <div class="table-container">
            <div class="filter-header">
                <div class="controls-row">
                    <div class="search-wrapper">
                        <i class="fas fa-search"></i>
                        <input type="text" name="search" class="input-field search-box" placeholder="Search records..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    
                    <select name="cause" class="input-field filter-select" onchange="this.form.submit()">
                        <option value="">All Medical Causes</option>
                        <?php foreach($unique_causes as $cause): ?>
                            <option value="<?php echo htmlspecialchars($cause); ?>" <?php if($cause_filter == $cause) echo 'selected'; ?>>
                                <?php echo htmlspecialchars($cause); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <select name="type_filter" class="input-field filter-select" onchange="this.form.submit()">
                        <option value="">All Assistance Types</option>
                        <?php foreach($unique_types as $type): ?>
                            <option value="<?php echo htmlspecialchars($type); ?>" <?php if($type_filter == $type) echo 'selected'; ?>>
                                <?php echo htmlspecialchars($type); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <select name="status_filter" class="input-field filter-select" onchange="this.form.submit()">
                        <option value="">All Statuses</option>
                        <?php 
                        $statuses = ['Pending', 'Approved', 'Paid', 'Waitlisted', 'Declined'];
                        foreach($statuses as $s): ?>
                            <option value="<?php echo $s; ?>" <?php if($status_filter == $s) echo 'selected'; ?>><?php echo $s; ?></option>
                        <?php endforeach; ?>
                    </select>

                    <div style="display: flex; align-items: center; gap: 8px;">
                        <input type="date" name="start" class="input-field" value="<?php echo htmlspecialchars($start_date); ?>" onchange="this.form.submit()" title="Start Date">
                        <span style="font-size: 12px; color: #64748b; font-weight: 600;">to</span>
                        <input type="date" name="end" class="input-field" value="<?php echo htmlspecialchars($end_date); ?>" onchange="this.form.submit()" title="End Date">
                    </div>

                    <a href="records.php" class="btn-recent">
                        <i class="fas fa-clock"></i> Recent
                    </a>
                </div>
            </div>

            <table>
                <thead>
                    <tr>
                        <th style="width: 15%;">Date</th>
                        <th style="width: 25%;">Medical Cause</th>
                        <th style="width: 20%;">Assistance Type</th>
                        <th style="width: 15%;">Status</th> 
                        <th style="width: 25%; text-align: center;" class="no-print">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($all_records)): ?>
                    <?php foreach ($all_records as $row): 
                        $fn = $row['fname'] ?? '';
                        $mn = $row['mname'] ?? '';
                        $ln = $row['lname'] ?? '';
                        $fullname = trim("$fn $mn $ln") ?: "Unknown Beneficiary";
                        $brgy = $row['barangay'] ?? 'N/A';
                        
                        $bdate = (!empty($row['birth_date']) && $row['birth_date'] != '0000-00-00') 
                         ? date("Y-m-d", strtotime($row['birth_date'])) 
                         : 'N/A';
                        
                        $idNum = $row['id_number'] ?? 'N/A';
                        $status = $row['status'] ?: 'Pending';
                    ?>
                    <tr>
                        <td><?php echo date("M d, Y", strtotime($row['request_date'])); ?></td>
                        <td style="font-weight:600;"><?php echo htmlspecialchars($row['medical_cause']); ?></td>
                        <td><span class="badge"><?php echo htmlspecialchars($row['assistance_type']); ?></span></td>
                        <td>
    <span class="status-badge status-<?php echo strtolower($status); ?>">
        <?php echo htmlspecialchars($status); ?>
    </span>
</td>

<td class="no-print" style="text-align:center;">

    <!-- VIEW PROFILE -->
    <a href="view_record_profile.php?id=<?php echo $row['id']; ?>" 
       target="_blank"
       class="action-btn btn-view"
       title="View Printable Profile">
        <i class="fas fa-eye"></i>
    </a>

    <!-- EDIT -->
    <button type="button"
        class="action-btn btn-edit"
        title="Edit Record"
        onclick="openModal(
            '<?php echo $row['id']; ?>',
            '<?php echo addslashes($row['medical_cause']); ?>',
            '<?php echo addslashes($row['assistance_type']); ?>',
            '<?php echo addslashes($status); ?>',
            '<?php echo $row['request_date']; ?>',
            '<?php echo addslashes($fullname); ?>',
            '<?php echo addslashes($brgy); ?>',
            '<?php echo $bdate; ?>',
            '<?php echo $idNum; ?>',
        )">
        <i class="fas fa-edit"></i>
    </button>

    

    <!-- APPROVE -->
    <a href="records.php?action=approve&id=<?php echo $row['id']; ?>"
       class="action-btn btn-approve"
       onclick="return confirm('Approve this record?')">
        <i class="fas fa-check"></i>
    </a>

    <!-- DELETE -->
    <button type="button"
        class="action-btn btn-delete"
        onclick="openDeleteModal('<?php echo $row['id']; ?>')">
        <i class="fas fa-trash"></i>
    </button>

</td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="5" style="text-align:center; padding: 40px;">No records found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>

            <?php if ($total_pages >= 1): ?>
            <div class="pagination-footer">
                <div class="pagination-info">
                    <?php 
                        $start_count = $offset + 1;
                        $end_count = min($offset + $limit, $total_records);
                        echo "$start_count - $end_count of $total_records"; 
                    ?>
                </div>
                <div class="pagination-btns">
                    <a href="<?php echo getPaginationUrl($page - 1); ?>" class="pg-btn <?php if($page <= 1) echo 'disabled'; ?>">
                        <i class="fas fa-chevron-left"></i>
                    </a>
                    <a href="<?php echo getPaginationUrl($page + 1); ?>" class="pg-btn <?php if($page >= $total_pages) echo 'disabled'; ?>">
                        <i class="fas fa-chevron-right"></i>
                    </a>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </form>
</div>


<div id="editModal" class="modal-overlay">
    <div class="modal-box">
        <h2 style="margin-top:0;">Edit Record</h2>
        <form method="POST">
            <input type="hidden" name="update_record" value="1">
            <input type="hidden" name="edit_id" id="m_id">
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom:15px; background: #f8fafc; padding: 10px; border-radius: 8px; border: 1px solid #e2e8f0;">
                <div>
                    <label style="font-size:10px; font-weight:700; color: #64748b;">ID NUMBER</label>
                    <input type="text" id="read_id" class="modal-input" style="background:#f1f5f9; border:none; font-weight:600;" readonly>
                </div>
                <div>
                    <label style="font-size:10px; font-weight:700; color: #64748b;">BARANGAY</label>
                    <input type="text" id="read_brgy" class="modal-input" style="background:#f1f5f9; border:none; font-weight:600;" readonly>
                </div>
                <div>
                    <label style="font-size:10px; font-weight:700; color: #64748b;">REQUEST DATE</label>
                    <input type="text" id="read_rdate" class="modal-input" style="background:#f1f5f9; border:none; font-weight:600;" readonly>
                </div>
                <div>
                    <label style="font-size:10px; font-weight:700; color: #64748b;">FULL NAME</label>
                    <input type="text" id="read_name" class="modal-input" style="background:#f1f5f9; border:none; font-weight:600;" readonly>
                </div>
            </div>
            <div style="margin-bottom:15px;">
                <label style="font-size:12px; font-weight:700;">MEDICAL CAUSE</label>
                <input type="text" id="m_cause" class="modal-input" style="background:#f1f5f9;" readonly>
            </div>
            <div style="margin-bottom:15px;">
                <label style="font-size:12px; font-weight:700;">ASSISTANCE TYPE</label>
                <input type="text" id="m_type" class="modal-input" style="background:#f1f5f9;" readonly>
            </div>
            <div style="margin-bottom:15px;">
                <label style="font-size:12px; font-weight:700;">STATUS</label>
                <select name="status" id="m_status" class="modal-input" required>
                    <option value="Pending">Pending</option>
                    <option value="Approved">Approved</option>
                    <option value="Paid">Paid</option>
                    <option value="Waitlisted">Waitlisted</option>
                    <option value="Declined">Declined</option>
                </select>
            </div>

            <?php if($_SESSION['role'] === 'Staff'): ?>
            <div style="background: #fff1f2; padding: 15px; border-radius: 8px; border: 1px solid #fecaca; margin-bottom: 20px;">
                <label style="font-size:11px; color:#e11d48; font-weight:700;">ADMIN AUTHORIZATION</label>
                <input type="password" name="admin_pass" class="modal-input" placeholder="Enter Admin Password" required>
            </div>
            <?php endif; ?>

            <div style="display:flex; justify-content: flex-end; gap: 10px;">
                <button type="button" onclick="closeModal()" class="action-btn">Cancel</button>
                <button type="submit" style="background:#3b82f6; color:#fff; border:none; padding:10px 20px; border-radius:8px; cursor:pointer; font-weight:600;">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<div id="historyModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeHistoryModal()">&times;</span>
        <h3><i class="fas fa-history"></i> Audit Trail - Record #<span id="historyRecordId"></span></h3>
        <hr style="border: 0; border-top: 1px solid #e2e8f0; margin: 15px 0;">
        <div id="historyContent">
            <p>Loading history...</p>
        </div>
    </div>
</div>

<div id="deleteModal" class="modal-overlay">
    <div class="modal-box" style="width: 350px; text-align: center;">
        <i class="fas fa-user-shield" style="font-size: 40px; color: #ef4444; margin-bottom: 15px;"></i>
        <h3>Admin Authorization</h3>
        <form method="POST">
            <input type="hidden" name="delete_record" value="1">
            <input type="hidden" name="delete_id" id="d_id">
            <input type="password" name="admin_pass" class="modal-input" placeholder="Enter Admin Password" required style="text-align: center; margin-bottom: 15px;">
            <div style="display:flex; gap: 10px;">
                <button type="button" onclick="closeDeleteModal()" class="action-btn" style="flex:1">Cancel</button>
                <button type="submit" style="background:#ef4444; color:#fff; border:none; padding:10px; border-radius:8px; cursor:pointer; flex:1;">Confirm Delete</button>
            </div>
        </form>
    </div>
</div>

<script>





function openModal(id, cause, type, status, rdate, name, brgy, bdate, idNum, remarks) {
    document.getElementById('m_id').value = id;
    document.getElementById('read_id').value = idNum;
    document.getElementById('read_brgy').value = brgy;
    document.getElementById('read_rdate').value = rdate;
    document.getElementById('read_name').value = name;
    document.getElementById('m_cause').value = cause;
    document.getElementById('m_type').value = type;
    document.getElementById('m_status').value = status;
    document.getElementById('editModal').style.display = 'block';
}

function closeModal() {
    document.getElementById('editModal').style.display = 'none';
}

function viewHistory(id) {
    document.getElementById('historyRecordId').innerText = id;
    document.getElementById('historyModal').style.display = 'block';
    document.getElementById('historyContent').innerHTML = '<p>Loading history...</p>';
    
    fetch('fetch_history.php?id=' + id)
        .then(response => response.text())
        .then(data => {
            document.getElementById('historyContent').innerHTML = data;
        });
}

function closeHistoryModal() {
    document.getElementById('historyModal').style.display = 'none';
}

function openDeleteModal(id) {
    document.getElementById('d_id').value = id;
    document.getElementById('deleteModal').style.display = 'block';
}

function closeDeleteModal() {
    document.getElementById('deleteModal').style.display = 'none';
}

window.onclick = function(event) {

    let editM = document.getElementById('editModal');
    let histM = document.getElementById('historyModal');
    let delM  = document.getElementById('deleteModal');

    if (event.target == editM) {
        editM.style.display = "none";
    }

    if (event.target == histM) {
        histM.style.display = "none";
    }

    if (event.target == delM) {
        delM.style.display = "none";
    }
}
</script>
<!-- CSV IMPORT MODAL -->
<div id="importModal" class="modal-overlay">
    <div class="modal-box" style="width: 520px; max-width: 95vw;">
        <h2 style="margin-top:0; display:flex; align-items:center; gap:10px;">
            <span style="background:#dcfce7; color:#16a34a; width:36px; height:36px; border-radius:8px; display:flex; align-items:center; justify-content:center;">
                <i class="fas fa-file-csv"></i>
            </span>
            Import Records from CSV
        </h2>
        <p style="color:#64748b; font-size:13px; margin-bottom:18px;">Upload a <strong>.csv file</strong> to bulk-import beneficiary records. New records are added — existing ones are not modified.</p>

        <!-- Column guide -->
        <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:14px; margin-bottom:18px; font-size:12px;">
            <div style="font-weight:700; color:#334155; margin-bottom:8px; text-transform:uppercase; letter-spacing:.05em; font-size:11px;">
                <i class="fas fa-table" style="color:#3b82f6; margin-right:5px;"></i> Accepted Column Headers
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:4px 16px; color:#64748b; line-height:1.8;">
                <span><code style="background:#e2e8f0; padding:1px 5px; border-radius:3px; color:#334155;">id_number</code> or <code style="background:#e2e8f0; padding:1px 5px; border-radius:3px; color:#334155;">id number</code></span>
                <span><code style="background:#e2e8f0; padding:1px 5px; border-radius:3px; color:#334155;">request_date</code> or <code style="background:#e2e8f0; padding:1px 5px; border-radius:3px; color:#334155;">date</code></span>
                <span><code style="background:#e2e8f0; padding:1px 5px; border-radius:3px; color:#334155;">fname</code>, <code style="background:#e2e8f0; padding:1px 5px; border-radius:3px; color:#334155;">mname</code>, <code style="background:#e2e8f0; padding:1px 5px; border-radius:3px; color:#334155;">lname</code></span>
                <span><code style="background:#e2e8f0; padding:1px 5px; border-radius:3px; color:#334155;">barangay</code> or <code style="background:#e2e8f0; padding:1px 5px; border-radius:3px; color:#334155;">brgy</code></span>
                <span><code style="background:#e2e8f0; padding:1px 5px; border-radius:3px; color:#334155;">birth_date</code> or <code style="background:#e2e8f0; padding:1px 5px; border-radius:3px; color:#334155;">birthdate</code></span>
                <span><code style="background:#e2e8f0; padding:1px 5px; border-radius:3px; color:#334155;">sex</code> or <code style="background:#e2e8f0; padding:1px 5px; border-radius:3px; color:#334155;">gender</code></span>
                <span><code style="background:#e2e8f0; padding:1px 5px; border-radius:3px; color:#334155;">civil_status</code> / <code style="background:#e2e8f0; padding:1px 5px; border-radius:3px; color:#334155;">civil status</code></span>
                <span><code style="background:#e2e8f0; padding:1px 5px; border-radius:3px; color:#334155;">medical_cause</code> / <code style="background:#e2e8f0; padding:1px 5px; border-radius:3px; color:#334155;">diagnosis</code></span>
                <span><code style="background:#e2e8f0; padding:1px 5px; border-radius:3px; color:#334155;">assistance_type</code></span>
                <span><code style="background:#e2e8f0; padding:1px 5px; border-radius:3px; color:#334155;">client_category</code> / <code style="background:#e2e8f0; padding:1px 5px; border-radius:3px; color:#334155;">category</code></span>
                <span><code style="background:#e2e8f0; padding:1px 5px; border-radius:3px; color:#334155;">client_subcategory</code></span>
                <span><code style="background:#e2e8f0; padding:1px 5px; border-radius:3px; color:#334155;">status</code></span>
                <span><code style="background:#e2e8f0; padding:1px 5px; border-radius:3px; color:#334155;">remarks</code> or <code style="background:#e2e8f0; padding:1px 5px; border-radius:3px; color:#334155;">notes</code></span>
            </div>
            <div style="margin-top:10px; padding-top:10px; border-top:1px solid #e2e8f0; color:#94a3b8; font-size:11px;">
                <i class="fas fa-info-circle" style="color:#3b82f6;"></i>
                Column names are <strong>case-insensitive</strong>. Dates should be <strong>YYYY-MM-DD</strong> or common formats (MM/DD/YYYY, etc.). Missing <em>status</em> defaults to <strong>Pending</strong>.
            </div>
        </div>

        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="import_csv" value="1">

            <!-- File drop zone -->
            <div id="dropZone" style="border:2px dashed #cbd5e1; border-radius:10px; padding:28px; text-align:center; cursor:pointer; transition:0.2s; background:#fafafa; margin-bottom:16px;"
                 onclick="document.getElementById('csvFileInput').click()"
                 ondragover="event.preventDefault(); this.style.borderColor='#3b82f6'; this.style.background='#eff6ff';"
                 ondragleave="this.style.borderColor='#cbd5e1'; this.style.background='#fafafa';"
                 ondrop="handleDrop(event)">
                <i class="fas fa-cloud-upload-alt" style="font-size:32px; color:#94a3b8; display:block; margin-bottom:8px;"></i>
                <div style="font-weight:600; color:#334155; font-size:14px;">Click or drag & drop your CSV file here</div>
                <div id="dropZoneFileName" style="color:#94a3b8; font-size:12px; margin-top:4px;">Only .csv files are accepted</div>
            </div>
            <input type="file" id="csvFileInput" name="csv_file" accept=".csv" style="display:none;" onchange="updateFileName(this)">

            <?php if($_SESSION['role'] === 'Staff'): ?>
            <div style="background: #fff1f2; padding: 15px; border-radius: 8px; border: 1px solid #fecaca; margin-bottom: 16px;">
                <label style="font-size:11px; color:#e11d48; font-weight:700;"><i class="fas fa-user-shield"></i> ADMIN AUTHORIZATION REQUIRED</label>
                <input type="password" name="admin_pass_import" class="modal-input" placeholder="Enter Admin Password" required style="margin-top:6px;">
            </div>
            <?php endif; ?>

            <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:4px;">
                <button type="button" onclick="closeImportModal()" class="action-btn" style="padding:10px 18px; font-size:13px;">Cancel</button>
                <button type="submit" id="importSubmitBtn" disabled
                    style="background:#16a34a; color:#fff; border:none; padding:10px 22px; border-radius:8px; cursor:pointer; font-weight:600; font-size:14px; opacity:0.5; transition:0.2s;"
                    onclick="this.innerHTML='<i class=\'fas fa-spinner fa-spin\'></i> Importing…'; this.disabled=true; this.closest(\'form\').submit();">
                    <i class="fas fa-upload"></i> Import Records
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openImportModal() {
    document.getElementById('importModal').style.display = 'block';
}
function closeImportModal() {
    document.getElementById('importModal').style.display = 'none';
    // Reset state
    document.getElementById('csvFileInput').value = '';
    document.getElementById('dropZoneFileName').textContent = 'Only .csv files are accepted';
    document.getElementById('dropZone').style.borderColor = '#cbd5e1';
    document.getElementById('dropZone').style.background = '#fafafa';
    const btn = document.getElementById('importSubmitBtn');
    btn.disabled = true;
    btn.style.opacity = '0.5';
}

function updateFileName(input) {
    const btn = document.getElementById('importSubmitBtn');
    if (input.files && input.files.length > 0) {
        const name = input.files[0].name;
        if (!name.toLowerCase().endsWith('.csv')) {
            document.getElementById('dropZoneFileName').textContent = '⚠ Please select a .csv file only.';
            document.getElementById('dropZone').style.borderColor = '#ef4444';
            btn.disabled = true; btn.style.opacity = '0.5';
            return;
        }
        document.getElementById('dropZoneFileName').textContent = '✓ ' + name;
        document.getElementById('dropZone').style.borderColor = '#16a34a';
        document.getElementById('dropZone').style.background = '#f0fdf4';
        btn.disabled = false; btn.style.opacity = '1';
    }
}

function handleDrop(event) {
    event.preventDefault();
    document.getElementById('dropZone').style.borderColor = '#cbd5e1';
    document.getElementById('dropZone').style.background = '#fafafa';
    const files = event.dataTransfer.files;
    if (files.length > 0) {
        const input = document.getElementById('csvFileInput');
        // Assign dropped file to the input
        const dt = new DataTransfer();
        dt.items.add(files[0]);
        input.files = dt.files;
        updateFileName(input);
    }
}

// Close import modal on outside click
window.addEventListener('click', function(event) {
    const importModal = document.getElementById('importModal');
    if (event.target === importModal) closeImportModal();
});
</script>

</body>
</html>