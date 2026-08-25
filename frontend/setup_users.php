<?php

require_once(__DIR__ . '/../db.php');

// 1. Re-create table with correct column length
$sql = "CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL
)";
$conn->query($sql);

// 2. Clear old test accounts
$conn->query("DELETE FROM users WHERE username IN ('dswd_admin', 'dswd_staff')");

// 3. Generate fresh hashes using PHP directly on your local system
$admin_hash = password_hash('Dswd@dmin!2026', PASSWORD_BCRYPT);
$staff_hash = password_hash('Dswdst@ff!2026', PASSWORD_BCRYPT);

// 4. Insert updated records
$stmt = $conn->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, ?)");

$admin_user = 'dswd_admin';
$admin_role = 'Admin';
$stmt->bind_param("sss", $admin_user, $admin_hash, $admin_role);
$stmt->execute();

$staff_user = 'dswd_staff';
$staff_role = 'Staff';
$stmt->bind_param("sss", $staff_user, $staff_hash, $staff_role);
$stmt->execute();

echo "<h2>Accounts Successfully Reset!</h2>";
echo "<p>Admin Hash Saved: " . $admin_hash . "</p>";
echo "<p>Staff Hash Saved: " . $staff_hash . "</p>";
echo "<a href='login.php'>Go to Login Page</a>";
?>