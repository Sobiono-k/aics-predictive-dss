<?php 
//forgot_password.php

if (count(get_included_files()) > 1) {
    http_response_code(403);
    exit();
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Force UTC timezone across PHP
date_default_timezone_set('UTC');
$bgPath = '../images/dswdlogo1.jpg';
$logoPath = '../images/dswdlogo.png'; 

require_once(__DIR__ . '/../db.php');


// Align MySQL timezone with PHP
$conn->query("SET time_zone = '+00:00'");

$message = "";
$messageType = "";
$resetLink = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST['username']);

    // Check if user exists
    $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        // Generate secure 64-character hex token & set 15-minute expiration
        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', strtotime('+15 minutes'));

        // Store token in database
        $update = $conn->prepare("UPDATE users SET reset_token = ?, reset_expires = ? WHERE username = ?");
        $update->bind_param("sss", $token, $expires, $username);
        
        if ($update->execute()) {
            $resetLink = "reset_password.php?token=" . $token;
            $message = "Reset token generated successfully!";
            $messageType = "success";
        } else {
            $message = "Error generating reset link. Try again.";
            $messageType = "error";
        }
    } else {
        $message = "Username not found in our records.";
        $messageType = "error";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DSWD Portal - Forgot Password</title>
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
        .alert-success { background-color: #dcfce7; border: 1px solid #22c55e; color: #15803d; }

        .input-box {
            background: #f1f5f9; border-radius: 8px; padding: 12px 15px;
            display: flex; align-items: center; margin-bottom: 15px;
        }

        .input-box i { color: #94a3b8; margin-right: 12px; }
        .input-box input { background: transparent; border: none; outline: none; width: 100%; font-size: 14px; }

        .btn-signin {
            background: #003893; color: white; border: none; width: 100%;
            padding: 14px; border-radius: 8px; font-weight: 700; cursor: pointer; transition: 0.2s;
        }

        .btn-signin:hover { background: #002a6d; }

        .back-link {
            display: block; text-align: center; margin-top: 15px;
            font-size: 12px; color: #003893; text-decoration: none; font-weight: 600;
        }

        .token-card {
            background: #f8fafc; border: 1px dashed #008cff; border-radius: 6px;
            padding: 12px; text-align: center; margin-bottom: 15px;
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
            <h2>Forgot Password</h2>
            <div class="subtitle">Enter your username to request a secure reset link.</div>

            <?php if (!empty($message)): ?>
                <div class="alert-box <?php echo ($messageType === 'success') ? 'alert-success' : 'alert-error'; ?>">
                    <i class="fas <?php echo ($messageType === 'success') ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
                    <span><?php echo $message; ?></span>
                </div>
            <?php endif; ?>

            <?php if (!empty($resetLink)): ?>
                <div class="token-card">
                    <p style="font-size:11px; color:#475569; margin:0 0 8px 0;">Verification Link (Simulated Email):</p>
                    <a href="<?php echo $resetLink; ?>" style="color:#003893; font-weight:700; font-size:13px; text-decoration:none;">
                        Click here to reset password <i class="fas fa-arrow-right"></i>
                    </a>
                </div>
            <?php endif; ?>

                <button type="submit" class="btn-signin">Request Reset Link</button>
                <a href="login.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to Sign In</a>
            </form>
        </div>
    </div>

</body>
</html>