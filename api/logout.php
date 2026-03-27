<?php
/**
 * Logout API Endpoint
 * Destroys user session and redirects to login
 */

require_once '../includes/config.php';
require_once '../includes/database.php';
require_once '../includes/auth.php';

// Perform logout
$auth->logout();
$_SESSION['success'] = 'Logged out successfully.';

// Redirect to login page
header('Location: ../index.php');
exit;
