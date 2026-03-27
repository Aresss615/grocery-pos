<?php
/**
 * Login API Endpoint
 * Handles user authentication
 */

require_once '../includes/config.php';
require_once '../includes/database.php';
require_once '../includes/auth.php';

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['error'] = 'Invalid request method.';
    header('Location: ../index.php');
    exit;
}

// Get POST data
$username = isset($_POST['username']) ? trim($_POST['username']) : '';
$password = isset($_POST['password']) ? $_POST['password'] : '';

// Attempt login
if ($auth->login($username, $password)) {
    $_SESSION['success'] = 'Login successful. Redirecting...';
    header('Location: ../index.php?page=dashboard');
    exit;
} else {
    // Login failed, redirect back to login page
    header('Location: ../index.php?page=login');
    exit;
}
