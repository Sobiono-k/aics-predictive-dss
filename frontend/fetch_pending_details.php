<?php
// fetch_pending_details.php
// Called via AJAX from lookup_applicant.php to show full details of a pending applicant
require_once 'auth.php';
require_once(__DIR__ . '/../db.php');

if (isset($_POST['id'])) {
    $id = (int)$_POST['id'];
    $stmt = $conn->prepare("SELECT * FROM pending_applications WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    if ($row) {
        $fullname = ucwords(strtolower(trim($row['fname'] . ' ' . $row['mname'] . ' ' . $row['lname'])));
        $bdate = (!empty($row['birth_date']) && $row['birth_date'] !== '0000-00-00')
            ? date("F d, Y", strtotime($row['birth_date'])) : 'Not specified';
        $submitted = date("F d, Y g:i A", strtotime($row['submitted_at']));
        ?>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:18px;">
            <div>
                <label style="font-size:10px;font-weight:800;color:#64748b;text-transform:uppercase;">Full Name</label>
                <p style="margin:3px 0;font-size:14px;color:#1e293b;font-weight:700;"><?php echo htmlspecialchars($fullname); ?></p>
            </div>
            <div>
                <label style="font-size:10px;font-weight:800;color:#64748b;text-transform:uppercase;">Reference Code</label>
                <p style="margin:3px 0;font-size:13px;color:#3b82f6;font-weight:700;font-family:'Courier New',monospace;"><?php echo htmlspecialchars($row['application_code']); ?></p>
            </div>
            <div>
                <label style="font-size:10px;font-weight:800;color:#64748b;text-transform:uppercase;">Date of Birth</label>
                <p style="margin:3px 0;font-size:14px;color:#1e293b;"><?php echo $bdate; ?></p>
            </div>
            <div>
                <label style="font-size:10px;font-weight:800;color:#64748b;text-transform:uppercase;">Sex</label>
                <p style="margin:3px 0;font-size:14px;color:#1e293b;"><?php echo htmlspecialchars($row['sex'] ?? '—'); ?></p>
            </div>
            <div>
                <label style="font-size:10px;font-weight:800;color:#64748b;text-transform:uppercase;">Civil Status</label>
                <p style="margin:3px 0;font-size:14px;color:#1e293b;"><?php echo htmlspecialchars($row['civil_status'] ?? '—'); ?></p>
            </div>
            <div>
                <label style="font-size:10px;font-weight:800;color:#64748b;text-transform:uppercase;">Barangay</label>
                <p style="margin:3px 0;font-size:14px;color:#1e293b;"><?php echo htmlspecialchars($row['barangay'] ?? '—'); ?></p>
            </div>
            <div>
                <label style="font-size:10px;font-weight:800;color:#64748b;text-transform:uppercase;">Medical Cause</label>
                <p style="margin:3px 0;font-size:14px;color:#1e293b;"><?php echo htmlspecialchars($row['medical_cause']); ?></p>
            </div>
            <div>
                <label style="font-size:10px;font-weight:800;color:#64748b;text-transform:uppercase;">Assistance Type</label>
                <p style="margin:3px 0;font-size:14px;color:#10b981;font-weight:600;"><?php echo htmlspecialchars($row['assistance_type']); ?></p>
            </div>
            <div>
                <label style="font-size:10px;font-weight:800;color:#64748b;text-transform:uppercase;">Client Category</label>
                <p style="margin:3px 0;font-size:14px;color:#1e293b;"><?php echo htmlspecialchars($row['client_category'] ?? '—'); ?></p>
            </div>
            <div>
                <label style="font-size:10px;font-weight:800;color:#64748b;text-transform:uppercase;">Submitted At</label>
                <p style="margin:3px 0;font-size:12px;color:#64748b;"><?php echo $submitted; ?></p>
            </div>
        </div>
        <?php
    } else {
        echo '<p style="text-align:center;color:#ef4444;">Record not found.</p>';
    }
}
$conn->close();
?>