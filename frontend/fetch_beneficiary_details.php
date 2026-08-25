<?php
// fetch_beneficiary_details.php
require_once 'auth.php'; 

require_once __DIR__ . '/db.php';

if (isset($_POST['id'])) {
    $id = (int)$_POST['id'];
    
    $stmt = $conn->prepare("SELECT * FROM aics_sample_data WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        $bdate = $row['birth_date'];
        $formatted_bdate = ($bdate && $bdate != '0000-00-00') ? date("F d, Y", strtotime($bdate)) : 'Not Specified';
        $rdate = date("M d, Y", strtotime($row['request_date']));
        ?>
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 25px; font-family: 'Inter', sans-serif;">
            <div style="border-right: 1px solid #f1f5f9; padding-right: 10px;">
                <div style="margin-bottom: 15px;">
                    <label style="font-size: 10px; font-weight: 800; color: #64748b; text-transform: uppercase;">Full Name</label>
                    <p style="margin: 3px 0; font-size: 14px; color: #1e293b; font-weight: 600;">
                        <?php echo htmlspecialchars(ucwords($row['fname'] . ' ' . ($row['mname'] ? $row['mname'] . ' ' : '') . $row['lname'])); ?>
                    </p>
                </div>

                <div style="margin-bottom: 15px;">
                    <label style="font-size: 10px; font-weight: 800; color: #64748b; text-transform: uppercase;">ID Number</label>
                    <p style="margin: 3px 0; font-size: 14px; color: #3b82f6; font-weight: 700;"><?php echo htmlspecialchars($row['id_number']); ?></p>
                </div>

                <div style="margin-bottom: 15px;">
                    <label style="font-size: 10px; font-weight: 800; color: #64748b; text-transform: uppercase;">Date of Birth</label>
                    <p style="margin: 3px 0; font-size: 14px; color: #1e293b;"><?php echo $formatted_bdate; ?></p>
                </div>

                <div>
                    <label style="font-size: 10px; font-weight: 800; color: #64748b; text-transform: uppercase;">Barangay</label>
                    <p style="margin: 3px 0; font-size: 14px; color: #1e293b;"><?php echo htmlspecialchars($row['barangay']); ?></p>
                </div>
            </div>

            <div>
                <div style="margin-bottom: 15px;">
                    <label style="font-size: 10px; font-weight: 800; color: #64748b; text-transform: uppercase;">Request Date</label>
                    <p style="margin: 3px 0; font-size: 14px; color: #1e293b;"><?php echo $rdate; ?></p>
                </div>

                <div style="margin-bottom: 15px;">
                    <label style="font-size: 10px; font-weight: 800; color: #64748b; text-transform: uppercase;">Medical Cause</label>
                    <p style="margin: 3px 0; font-size: 14px; color: #1e293b;"><?php echo htmlspecialchars($row['medical_cause']); ?></p>
                </div>

                <div style="margin-bottom: 15px;">
                    <label style="font-size: 10px; font-weight: 800; color: #64748b; text-transform: uppercase;">Type of Assistance</label>
                    <p style="margin: 3px 0; font-size: 14px; color: #10b981; font-weight: 600;"><?php echo htmlspecialchars($row['assistance_type']); ?></p>
                </div>

                <div>
                    <label style="font-size: 10px; font-weight: 800; color: #64748b; text-transform: uppercase;">Application Status</label>
                    <div style="margin-top: 5px;">
                        <span class="status-badge status-<?php echo strtolower($row['status']); ?>" style="padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 700; text-transform: uppercase;">
                            <?php echo htmlspecialchars($row['status']); ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <div style="margin-top: 25px; padding: 15px; background: #f8fafc; border-radius: 8px; border: 1px solid #e2e8f0;">
            <label style="font-size: 10px; font-weight: 800; color: #64748b; text-transform: uppercase; display: block; margin-bottom: 5px;">Administrative Remarks</label>
            <p style="margin: 0; font-size: 13px; color: #334155; line-height: 1.5;">
                <?php 
                    if (!empty($row['remarks'])) {
                        echo nl2br(htmlspecialchars($row['remarks'])); 
                    } else {
                        echo '<span style="color: #94a3b8; font-style: italic;">No specific remarks or notes added for this beneficiary.</span>';
                    }
                ?>
            </p>
        </div>
        <?php
    } else {
        echo "<div style='padding: 20px; text-align: center; color: #ef4444;'><strong>Error:</strong> Beneficiary record not found.</div>";
    }
}
$conn->close();
?>