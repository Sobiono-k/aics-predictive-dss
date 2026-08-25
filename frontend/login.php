<?php 
    $bgPath = '../images/dswdlogo1.jpg';
    $logoPath = '../images/dswdlogo.png'; 

    // Check if the remember_user cookie exists to auto-fill the form
    $saved_username = "";
    $is_remembered = false;

    if (isset($_COOKIE['remember_user'])) {
        $saved_username = htmlspecialchars($_COOKIE['remember_user']);
        $is_remembered = true;
    }
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DSWD Portal - Sign In</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body, html {
            margin: 0;
            padding: 0;
            height: 100%;
            width: 100%;
            font-family: 'Segoe UI', sans-serif;
            overflow: hidden;
        }

        .fullscreen-bg {
            background: linear-gradient(rgba(1, 20, 54, 0.8), rgba(0, 56, 147, 0.56)), 
                        url('<?php echo $bgPath; ?>');
            background-size: cover;
            background-position: center;
            height: 100vh;
            width: 100%;
            display: flex;
            justify-content: center; 
            align-items: center;
            gap: 80px; 
            box-sizing: border-box;
        }

        .logo-section {
            text-align: center;
        }

        .center-logo {
            max-width: 450px; 
            height: auto;
            filter: drop-shadow(0 25px 20px rgb(255, 255, 255));
        }

        .login-card {
            background: white;
            padding: 40px;
            border-radius: 4px;
            width: 380px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.4);
            border-top: 6px solid #008cff;
        }

        .login-card h2 {
            margin: 0 0 10px 0;
            color: #1e293b;
            font-weight: 700;
        }

        .subtitle {
            font-size: 13px;
            color: #64748b;
            margin-bottom: 20px;
        }

        /* Error alert notification styling */
        .error-alert {
            background-color: #fee2e2;
            border: 1px solid #ef4444;
            color: #b91c1c;
            padding: 10px 12px;
            border-radius: 6px;
            font-size: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .input-box {
            background: #f1f5f9;
            border-radius: 8px;
            padding: 12px 15px;
            display: flex;
            align-items: center;
            margin-bottom: 15px;
        }

        .input-box i { color: #94a3b8; margin-right: 12px; }

        .input-box input {
            background: transparent;
            border: none;
            outline: none;
            width: 100%;
            font-size: 14px;
        }

        .password-toggle {
            cursor: pointer;
            font-size: 12px;
            color: #4285F4;
            font-weight: 700;
        }

        .options {
            display: flex;
            justify-content: space-between;
            font-size: 12px;
            color: #64748b;
            margin: 15px 0 25px 0;
        }

        .btn-signin {
            background: #003893;
            color: white;
            border: none;
            width: 100%;
            padding: 14px;
            border-radius: 8px;
            font-weight: 700;
            cursor: pointer;
            transition: 0.2s;
        }

        .btn-signin:hover { background: #002a6d; }

        @media (max-width: 900px) {
            .fullscreen-bg { flex-direction: column; gap: 30px; }
            .center-logo { max-width: 280px; }
        }
    </style>
</head>
<body>

    <div class="fullscreen-bg">
        <div class="logo-section">
            <img src="<?php echo $logoPath; ?>" alt="DSWD Logo" class="center-logo">
        </div>

        <div class="login-card">
            <h2>Sign in</h2>
            <div class="subtitle">Enter your credentials to access your account.</div>

            <!-- Dynamic Error Alert Notice -->
            <?php if (isset($_GET['error']) && $_GET['error'] === 'invalid'): ?>
                <div class="error-alert">
                    <i class="fas fa-circle-exclamation"></i>
                    <span>Invalid username or password. Please try again.</span>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_GET['reset']) && $_GET['reset'] === 'success'): ?>
    <div style="background-color: #dcfce7; border: 1px solid #22c55e; color: #15803d; padding: 10px; border-radius: 6px; font-size: 12px; margin-bottom: 20px;">
        <i class="fas fa-circle-check"></i> Password updated successfully! Please sign in.
    </div>
<?php endif; ?>

            <form action="login_action.php" method="POST">
                <div class="input-box">
                    <i class="fas fa-user"></i>
                    <input type="text" name="username" placeholder="User Name" value="<?php echo $saved_username; ?>" required>
                </div>

                <div class="input-box">
                    <i class="fas fa-lock"></i>
                    <input type="password" name="password" id="passwordField" placeholder="Password" required>
                    <i class="fas fa-eye password-toggle" id="toggleIcon" onclick="togglePassword()" style="cursor: pointer; color: #4285F4;"></i>
                </div>

                <div class="options">
                    <label style="display: flex; align-items: center; gap: 5px; cursor: pointer;">
                        <input type="checkbox" name="remember" <?php echo $is_remembered ? 'checked' : ''; ?>> Remember me
                    </label>
                        <a href="forgot_password.php" style="color: #003893; text-decoration: none; font-weight: 600;">Forgot password?</a>
                </div>

                <button type="submit" class="btn-signin">Sign in</button>
                
            </form>
        </div>
    </div>

    <script>
        function togglePassword() {
            const field = document.getElementById('passwordField');
            const icon = document.getElementById('toggleIcon');
            
            if (field.type === "password") {
                field.type = "text";
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                field.type = "password";
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }
    </script>

</body>
</html>