<?php
/**
 * J&J Grocery POS - Login Handler
 * Processes authentication requests
 */

session_start();

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(BASE_URL . '/index.php');
}

// Initialize database
$db = new Database();

$username = sanitize($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';

// Validate inputs
if (empty($username) || empty($password)) {
    redirectWithMessage(BASE_URL . '/index.php', 'Username and password required', 'error');
}

// Find user in database (include role_id for v4+ permission system)
$user = $db->fetchOne(
    "SELECT id, name, username, password, role, role_id FROM users WHERE username = ? AND active = 1",
    [$username]
);

if (!$user) {
    redirectWithMessage(BASE_URL . '/index.php', 'Invalid username or password', 'error');
}

// Verify password with bcrypt
if (!password_verify($password, $user['password'])) {
    redirectWithMessage(BASE_URL . '/index.php', 'Invalid username or password', 'error');
}

// Set session (pass $db so permissions are loaded from role_permissions table)
setUserSession($user, $db);

// Redirect based on role
$dashboard = BASE_URL . '/pages/dashboard.php';

if ($user['role'] === 'inventory_checker') {
    $dashboard = BASE_URL . '/pages/inventory.php';
}

redirectWithMessage($dashboard, 'Welcome ' . $user['name'], 'success');
