<?php
// login.php
require_once 'connection.php';

// Check if already logged in
if (is_logged_in()) {
    header('Location: dashboard.php');
    exit();
}

$error = '';
$success = '';
$show_reset_form = false;
$reset_token = '';

// Handle forgot password request
if (isset($_GET['action']) && $_GET['action'] == 'forgot') {
    $show_reset_form = true;
}

// Handle reset token verification
if (isset($_GET['token'])) {
    $reset_token = $_GET['token'];
    if (verify_reset_token($reset_token)) {
        $show_reset_form = true;
    } else {
        $error = 'Invalid or expired reset link.';
    }
}

// Handle password reset request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['forgot_password'])) {
        // Forgot password form submitted
        $email = $_POST['email'] ?? '';
        
        if (empty($email)) {
            $error = 'Please enter your email address.';
        } elseif (send_password_reset($email)) {
            $success = 'Password reset instructions have been sent to your email.';
            $show_reset_form = false;
        } else {
            $error = 'Email not found in our system.';
        }
    }
    elseif (isset($_POST['reset_password'])) {
        // Reset password form submitted
        $token = $_POST['reset_token'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        if (empty($new_password) || empty($confirm_password)) {
            $error = 'Please enter and confirm your new password.';
        } elseif ($new_password !== $confirm_password) {
            $error = 'Passwords do not match.';
        } elseif (strlen($new_password) < 6) {
            $error = 'Password must be at least 6 characters long.';
        } elseif (reset_password($token, $new_password)) {
            $success = 'Your password has been reset successfully. You can now login with your new password.';
            $show_reset_form = false;
            $reset_token = '';
        } else {
            $error = 'Invalid or expired reset token.';
        }
    }
    else {
        // Regular login form submitted
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';
        
        if (authenticate_user($username, $password)) {
            header('Location: dashboard.php');
            exit();
        } else {
            $error = 'Invalid username or password';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MRBS - <?php echo $show_reset_form ? 'Reset Password' : 'Login'; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .login-container {
            width: 100%;
            max-width: 400px;
            background: white;
            border-radius: 15px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.2);
            overflow: hidden;
        }

        .login-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }

        .login-header h1 {
            font-size: 2rem;
            margin-bottom: 10px;
        }

        .login-header p {
            opacity: 0.9;
            font-size: 0.9rem;
        }

        .login-form {
            padding: 30px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #333;
            font-size: 0.95rem;
        }

        input {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s;
        }

        input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .error-message {
            background: #fee2e2;
            color: #991b1b;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .success-message {
            background: #d1fae5;
            color: #065f46;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .btn-login, .btn-reset {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .btn-login:hover, .btn-reset:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.2);
        }

        .btn-secondary {
            background: #6b7280;
        }

        .btn-secondary:hover {
            background: #4b5563;
            box-shadow: 0 10px 20px rgba(107, 114, 128, 0.2);
        }

        .links {
            display: flex;
            justify-content: space-between;
            margin-top: 20px;
            font-size: 0.9rem;
        }

        .links a {
            color: #667eea;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .links a:hover {
            text-decoration: underline;
        }

        .password-strength {
            margin-top: 5px;
            font-size: 0.8rem;
            color: #666;
        }

        .password-strength.weak { color: #ef4444; }
        .password-strength.medium { color: #f59e0b; }
        .password-strength.strong { color: #10b981; }

        .password-requirements {
            margin-top: 10px;
            font-size: 0.8rem;
            color: #666;
            padding: 10px;
            background: #f9fafb;
            border-radius: 6px;
            border-left: 3px solid #667eea;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <h1><i class="fas fa-calendar-alt"></i> MRBS <?php echo $show_reset_form ? 'Password Reset' : 'Login'; ?></h1>
            <p>Meeting Room Booking System</p>
        </div>
        
        <div class="login-form">
            <?php if ($error): ?>
                <div class="error-message">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="success-message">
                    <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($show_reset_form): ?>
                <!-- Forgot Password / Reset Password Form -->
                <form method="POST" action="" id="resetForm">
                    <?php if (empty($reset_token)): ?>
                        <!-- Request reset link -->
                        <div class="form-group">
                            <label for="email"><i class="fas fa-envelope"></i> Email Address</label>
                            <input type="email" id="email" name="email" required 
                                   placeholder="Enter your registered email"
                                   value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                        </div>
                        <input type="hidden" name="forgot_password" value="1">
                        <button type="submit" class="btn-reset">
                            <i class="fas fa-key"></i> Send Reset Link
                        </button>
                    <?php else: ?>
                        <!-- Reset password with token -->
                        <input type="hidden" name="reset_token" value="<?php echo htmlspecialchars($reset_token); ?>">
                        <div class="form-group">
                            <label for="new_password"><i class="fas fa-lock"></i> New Password</label>
                            <input type="password" id="new_password" name="new_password" required 
                                   placeholder="Enter new password (min. 6 characters)"
                                   onkeyup="checkPasswordStrength(this.value)">
                            <div id="passwordStrength" class="password-strength"></div>
                        </div>
                        
                        <div class="form-group">
                            <label for="confirm_password"><i class="fas fa-lock"></i> Confirm New Password</label>
                            <input type="password" id="confirm_password" name="confirm_password" required 
                                   placeholder="Confirm new password">
                        </div>
                        
                        <div class="password-requirements">
                            <strong>Password Requirements:</strong><br>
                            • At least 6 characters long<br>
                            • Use letters and numbers for better security
                        </div>
                        
                        <input type="hidden" name="reset_password" value="1">
                        <button type="submit" class="btn-reset">
                            <i class="fas fa-sync-alt"></i> Reset Password
                        </button>
                    <?php endif; ?>
                </form>
                
                <div class="links">
                    <a href="login.php">
                        <i class="fas fa-arrow-left"></i> Back to Login
                    </a>
                </div>
                
            <?php else: ?>
                <!-- Regular Login Form -->
                <form method="POST" action="">
                    <div class="form-group">
                        <label for="username"><i class="fas fa-user"></i> Username</label>
                        <input type="text" id="username" name="username" required 
                               placeholder="Enter your username" 
                               value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="password"><i class="fas fa-lock"></i> Password</label>
                        <input type="password" id="password" name="password" required 
                               placeholder="Enter your password">
                    </div>
                    
                    <button type="submit" class="btn-login">
                        <i class="fas fa-sign-in-alt"></i> Login
                    </button>
                </form>
                
                <div class="links">
                    <a href="dashboard.php">
                        <i class="fas fa-arrow-left"></i> Back to Dashboard
                    </a>
                    <a href="login.php?action=forgot">
                        <i class="fas fa-key"></i> Forgot Password?
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function checkPasswordStrength(password) {
            const strengthElement = document.getElementById('passwordStrength');
            if (!strengthElement) return;
            
            let strength = 0;
            let text = '';
            let className = '';
            
            if (password.length >= 6) strength++;
            if (password.length >= 8) strength++;
            if (/[A-Z]/.test(password)) strength++;
            if (/[0-9]/.test(password)) strength++;
            if (/[^A-Za-z0-9]/.test(password)) strength++;
            
            if (password.length === 0) {
                text = '';
                className = '';
            } else if (password.length < 6) {
                text = 'Too short (min 6 characters)';
                className = 'weak';
            } else if (strength <= 2) {
                text = 'Weak';
                className = 'weak';
            } else if (strength <= 3) {
                text = 'Medium';
                className = 'medium';
            } else {
                text = 'Strong';
                className = 'strong';
            }
            
            strengthElement.textContent = text;
            strengthElement.className = 'password-strength ' + className;
        }
        
        // Form validation
        document.addEventListener('DOMContentLoaded', function() {
            const resetForm = document.getElementById('resetForm');
            if (resetForm) {
                resetForm.addEventListener('submit', function(e) {
                    const newPassword = document.getElementById('new_password');
                    const confirmPassword = document.getElementById('confirm_password');
                    
                    if (newPassword && confirmPassword) {
                        if (newPassword.value !== confirmPassword.value) {
                            e.preventDefault();
                            alert('Passwords do not match!');
                            confirmPassword.focus();
                        } else if (newPassword.value.length < 6) {
                            e.preventDefault();
                            alert('Password must be at least 6 characters long!');
                            newPassword.focus();
                        }
                    }
                });
            }
        });
    </script>
</body>
</html>