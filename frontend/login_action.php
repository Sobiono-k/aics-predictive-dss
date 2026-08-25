<?php
session_start();

require_once 'db.php';


if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $user_input = trim($_POST['username']);
    $pass_input = trim($_POST['password']);
    
    // Tinitingnan kung pinindot ang Remember Me checkbox
    $remember = isset($_POST['remember']) ? true : false;

    $stmt = $conn->prepare("SELECT id, password, role FROM users WHERE username = ?");
    $stmt->bind_param("s", $user_input);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $row = $result->fetch_assoc();
        
        // Dynamic password match verification
        if (password_verify($pass_input, $row['password'])) {
            
            $_SESSION['user_id'] = $row['id'];
            $_SESSION['role'] = $row['role']; 
            $_SESSION['username'] = $user_input;

            // --- REMEMBER ME COOKIE HANDLING ---
            if ($remember) {
                // Sine-save ang username sa browser ng 30 araw (86400 segundo = 1 araw)
                setcookie("remember_user", $user_input, time() + (86400 * 30), "/"); 
            } else {
                // Kung hindi naka-check, pinupuwersang i-expire ang cookie upang mabura sa browser
                if (isset($_COOKIE['remember_user'])) {
                    setcookie("remember_user", "", time() - 3600, "/");
                }
            }

            // Role redirection map
            if ($_SESSION['role'] === 'Admin') {
                header("Location: index.php");
            } else {
                header("Location: new_applicant.php");
            }
            exit();

        } else {
            header("Location: login.php?error=invalid");
            exit();
        }
    } else {
        header("Location: login.php?error=invalid");
        exit();
    }
}
?>