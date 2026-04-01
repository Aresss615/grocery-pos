<?php
/**
 * J&J Grocery POS - Login Page
 * Entry point for authentication
 */

session_start();
require_once 'config/constants.php';
require_once 'config/helpers.php';

// If already logged in, redirect to dashboard
if (isLoggedIn()) {
    redirect(BASE_URL . '/pages/dashboard.php');
}

$page_title = 'Login';
?>
<!DOCTYPE html>
<html lang="fil">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo APP_NAME; ?> - <?php echo $page_title; ?></title>
    <link rel="stylesheet" href="<?php echo CSS_URL; ?>/main.css">
    <link rel="stylesheet" href="<?php echo CSS_URL; ?>/theme.css">
</head>
<body class="login-page">
    <div class="login-bg-decoration"></div>
    
    <div class="login-container">
        <div class="login-card">
            <!-- Logo & Header -->
            <div class="login-header">
                <img src="<?php echo IMG_URL; ?>/logo.jpg" alt="J&J Logo" style="max-height: 80px; margin-bottom: 15px;">
                <h1><?php echo APP_NAME; ?></h1>
                <p>Point of Sale System</p>
            </div>

            <!-- Messages -->
            <?php displayMessage(); ?>

            <!-- Login Form -->
            <form method="POST" action="<?php echo BASE_URL; ?>/auth/login.php" class="login-form">
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" class="form-input" required autofocus>
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" class="form-input" required>
                </div>

                <button type="submit" class="btn btn-primary btn-block">
                    🔓 Login
                </button>
            </form>

            <!-- Demo Credentials -->


            <!-- Footer -->
            <div class="login-footer">
                <p>&copy; 2026 J&J Grocery</p>
                <p class="text-muted">Modern POS System for Philippines</p>
            </div>
        </div>
    </div>

    <script src="<?php echo JS_URL; ?>/main.js"></script>
</body>
</html>
