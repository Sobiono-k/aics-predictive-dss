<?php 
    // Force UTC timezone across PHP to avoid local server time mismatches
    date_default_timezone_set('UTC');

    if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
    $bgPath = '../images/dswdlogo1.jpg';
    $logoPath = '../images/dswdlogo.png'; 
    
require_once(__DIR__ . '/../db.php');

    $token = $_GET['token'] ?? '';
    $tokenValid = false;
    $message = "";

    if (!empty($token)) {
        // Retrieve token and expiration timestamp
        $stmt = $conn->prepare("SELECT id, reset_expires FROM users WHERE reset_token = ?");
        $stmt->bind_param("s", $token);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $row = $result->fetch_assoc();
            $current_time = date('Y-m-d H:i:s');

            // Compare PHP current time against stored expiry time
            if ($row['reset_expires'] > $current_time) {
                $tokenValid = true;
            } else {
                $message = "This reset link has expired. Please request a new one.";
            }
        } else {
            $message = "Invalid or already used reset link.";
        }
    } else {
        $message = "No reset token provided.";
    }

    // Handle Password Update
    if ($_SERVER["REQUEST_METHOD"] == "POST" && $tokenValid) {
        $new_pass = trim($_POST['new_password']);
        $confirm_pass = trim($_POST['confirm_password']);

        if ($new_pass !== $confirm_pass) {
            $message = "New passwords do not match!";
        } else {
            // Hash password using BCRYPT
            $hashed_pass = password_hash($new_pass, PASSWORD_BCRYPT);

            // Invalidate token immediately after update
            $update = $conn->prepare("UPDATE users SET password = ?, reset_token = NULL, reset_expires = NULL WHERE reset_token = ?");
            $update->bind_param("ss", $hashed_pass, $token);

            if ($update->execute()) {
                header("Location: login.php?reset=success");
                exit();
            } else {
                $message = "Failed to update password. Try again.";
            }
        }
    }
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DSWD Portal - Set New Password</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body, html {
            margin: 0; padding: 0; height: 100%; width: 100%;
            font-family: 'Segoe UI', sans-serif; overflow: hidden;
        }

        .fullscreen-bg {
            background: linear-gradient(rgba(1, 20, 54, 0.8), rgba(0, 56, 147, 0.56)), url('<?php echo $bgPath; ?>');
            background-size: cover; background-position: center; height: 100vh;
            display: flex; justify-content: center; align-items: center; gap: 80px; box-sizing: border-box;
        }

        .center-logo { max-width: 450px; filter: drop-shadow(0 25px 20px rgb(255, 255, 255)); }

        .login-card {
            background: white; padding: 40px; border-radius: 4px; width: 380px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.4); border-top: 6px solid #008cff;
        }

        .login-card h2 { margin: 0 0 10px 0; color: #1e293b; font-weight: 700; }
        .subtitle { font-size: 13px; color: #64748b; margin-bottom: 20px; }

        .alert-box {
            padding: 10px 12px; border-radius: 6px; font-size: 12px;
            margin-bottom: 20px; display: flex; align-items: center; gap: 8px;
        }

        .alert-error { background-color: #fee2e2; border: 1px solid #ef4444; color: #b91c1c; }

        .input-box {
            background: #f1f5f9; border-radius: 8px; padding: 12px 15px;
            display: flex; align-items: center; margin-bottom: 15px;
        }

        .input-box i { color: #94a3b8; margin-right: 12px; }
        .input-box input { background: transparent; border: none; outline: none; width: 100%; font-size: 14px; }

        .password-toggle { cursor: pointer; font-size: 12px; color: #003893; }

        .btn-signin {
            background: #003893; color: white; border: none; width: 100%;
            padding: 14px; border-radius: 8px; font-weight: 700; cursor: pointer; transition: 0.2s;
        }

        .btn-signin:hover { background: #002a6d; }

        .back-link {
            display: block; text-align: center; margin-top: 15px;
            font-size: 12px; color: #003893; text-decoration: none; font-weight: 600;
        }

        @media (max-width: 900px) {
            .fullscreen-bg { flex-direction: column; gap: 30px; }
            .center-logo { max-width: 280px; }
        }
    </style>
</head>
<body>

    <div class="fullscreen-bg">
        <div>
            <img src="<?php echo $logoPath; ?>" alt="DSWD Logo" class="center-logo">
        </div>

        <div class="login-card">
            <h2>New Password</h2>
            <div class="subtitle">Set a new secure password for your account.</div>

            <?php if (!empty($message)): ?>
                <div class="alert-box alert-error">
                    <i class="fas fa-circle-exclamation"></i>
                    <span><?php echo $message; ?></span>
                </div>
            <?php endif; ?>

            <?php if ($tokenValid): ?>
                <form action="" method="POST">
                    <div class="input-box">
                        <i class="fas fa-lock"></i>
                        <input type="password" name="new_password" id="newPass" placeholder="New Password" required>
                        <i class="fas fa-eye password-toggle" onclick="togglePass('newPass', this)"></i>
                    </div>

                    <div class="input-box">
                        <i class="fas fa-lock"></i>
                        <input type="password" name="confirm_password" id="confirmPass" placeholder="Confirm Password" required>
                        <i class="fas fa-eye password-toggle" onclick="togglePass('confirmPass', this)"></i>
                    </div>

                    <button type="submit" class="btn-signin">Save New Password</button>
                </form>
            <?php else: ?>
                <a href="forgot_password.php" class="back-link"><i class="fas fa-arrow-left"></i> Request a new reset link</a>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function togglePass(inputId, icon) {
            const input = document.getElementById(inputId);
            if (input.type === "password") {
                input.type = "text";
                icon.classList.replace('fa-eye', 'fa-eye-slash');
            } else {
                input.type = "password";
                icon.classList.replace('fa-eye-slash', 'fa-eye');
            }
        }
    </script>

</body>
</html>