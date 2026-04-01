<?php
/**
 * API Endpoint - Get Product by ID
 * Used by AJAX to fetch product data for editing
 */

session_start();
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

header('Content-Type: application/json');

// Check auth and admin role
if (!isLoggedIn() || !hasAccess('products')) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$id = intval($_GET['id'] ?? 0);

if (!$id) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing product ID']);
    exit();
}

try {
    $db = new Database();
    $product = $db->fetchOne(
        "SELECT id, name, barcode, price_retail, price_wholesale, quantity, category_id, supplier_id FROM products WHERE id = ? AND active = 1 LIMIT 1",
        [$id]
    );

    if (!$product) {
        http_response_code(404);
        echo json_encode(['error' => 'Product not found']);
        exit();
    }

    // Get category and supplier names
    if ($product['category_id']) {
        $category = $db->fetchOne("SELECT id, name FROM categories WHERE id = ?", [$product['category_id']]);
        $product['category_name'] = $category['name'] ?? '';
    }
    if ($product['supplier_id']) {
        $supplier = $db->fetchOne("SELECT id, name FROM suppliers WHERE id = ?", [$product['supplier_id']]);
        $product['supplier_name'] = $supplier['name'] ?? '';
    }

    echo json_encode($product);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}
