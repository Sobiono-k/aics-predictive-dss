<?php
// 1. Database Configuration (Matches your records.php)
require_once(__DIR__ . '/../db.php');

if (isset($_POST['id'])) {
    $record_id = (int)$_POST['id'];

    // Fetch logs for this specific record
    $stmt = $conn->prepare("SELECT action_type, changed_column, old_value, new_value, created_at FROM audit_logs WHERE record_id = ? ORDER BY created_at DESC");
    $stmt->bind_param("i", $record_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        echo '<ul class="audit-list">';
        while ($row = $result->fetch_assoc()) {
            $date = date("M d, Y h:i A", strtotime($row['created_at']));
            echo "<li>";
            echo "<strong>" . htmlspecialchars($row['action_type']) . "</strong> on <em>" . htmlspecialchars($row['changed_column']) . "</em><br>";
            
            if ($row['action_type'] === 'UPDATE') {
                echo "Changed: <span class='old-val'>" . htmlspecialchars($row['old_value']) . "</span> &rarr; ";
                echo "<span class='new-val'>" . htmlspecialchars($row['new_value']) . "</span><br>";
            }
            
            echo "<small class='user-tag'>Timestamp: $date</small>";
            echo "</li>";
        }
        echo '</ul>';
    } else {
        echo '<p style="color:#64748b; padding:10px;">No changes recorded for this beneficiary yet.</p>';
    }
    $stmt->close();
}
$conn->close();
?>