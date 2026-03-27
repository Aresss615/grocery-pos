<?php
/**
 * API Endpoint - Get User by ID
 * Used by AJAX to fetch user data for editing
 */

session_start();
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

header('Content-Type: application/json');

// Check auth and admin role
if (!isLoggedIn() || !hasRole('admin')) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$id = intval($_GET['id'] ?? 0);

if (!$id) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing user ID']);
    exit();
}

try {
    $user = $db->fetchOne(
        "SELECT id, name, username, role FROM users WHERE id = ? AND active = 1 LIMIT 1",
        [$id]
    );

    if (!$user) {
        http_response_code(404);
        echo json_encode(['error' => 'User not found']);
        exit();
    }

    echo json_encode($user);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}
