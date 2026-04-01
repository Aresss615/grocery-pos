<?php
/**
 * Inventory Management API
 * Stock updates and inventory operations
 */

session_start();
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

header('Content-Type: application/json');

// Check auth
if (!isLoggedIn() || !hasAccess('inventory')) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$db = new Database();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'update_stock') {
    requireCsrf(true);
    $product_id = intval($_POST['product_id'] ?? 0);
    $quantity = intval($_POST['quantity'] ?? 0);
    
    if ($product_id <= 0) {
        echo json_encode(['error' => 'Invalid product ID']);
        exit();
    }
    
    $result = $db->execute(
        "UPDATE products SET quantity = ? WHERE id = ?",
        [$quantity, $product_id]
    );
    
    if ($result) {
        echo json_encode(['success' => true, 'message' => 'Stock updated successfully']);
    } else {
        echo json_encode(['error' => 'Failed to update stock']);
    }
}

elseif ($action === 'get_product') {
    $product_id = intval($_GET['product_id'] ?? 0);
    
    if ($product_id <= 0) {
        echo json_encode(['error' => 'Invalid product ID']);
        exit();
    }
    
    $product = $db->fetchOne(
        "SELECT * FROM products WHERE id = ?",
        [$product_id]
    );
    
    if ($product) {
        echo json_encode(['success' => true, 'data' => $product]);
    } else {
        echo json_encode(['error' => 'Product not found']);
    }
}

else {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid action']);
}
?>
