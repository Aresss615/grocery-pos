<?php
/**
 * Master Data Management API
 * Categories, Suppliers, and other master data CRUD operations
 */

session_start();
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

header('Content-Type: application/json');

// Check auth
if (!isLoggedIn() || !hasAccess('master_data')) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$db = new Database();
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$type = $_GET['type'] ?? $_POST['type'] ?? ''; // 'category' or 'supplier'

// CSRF validation for state-changing operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf(true);
}

// CATEGORIES CRUD
if ($type === 'category') {
    if ($action === 'list') {
        $data = $db->fetchAll("SELECT id, name FROM categories ORDER BY name");
        echo json_encode(['success' => true, 'data' => $data]);
    }
    elseif ($action === 'create') {
        $name = sanitize($_POST['name'] ?? '');
        if (!$name) {
            echo json_encode(['error' => 'Category name required']);
            exit();
        }
        
        $result = $db->execute("INSERT INTO categories (name) VALUES (?)", [$name]);
        if ($result) {
            $id = $db->lastInsertId();
            echo json_encode(['success' => true, 'id' => $id, 'message' => 'Category created']);
        } else {
            echo json_encode(['error' => 'Failed to create category']);
        }
    }
    elseif ($action === 'update') {
        $id = intval($_POST['id'] ?? 0);
        $name = sanitize($_POST['name'] ?? '');
        if ($id <= 0 || !$name) {
            echo json_encode(['error' => 'Invalid data']);
            exit();
        }
        
        $result = $db->execute("UPDATE categories SET name = ? WHERE id = ?", [$name, $id]);
        echo json_encode(['success' => $result, 'message' => 'Category updated']);
    }
    elseif ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['error' => 'Invalid ID']);
            exit();
        }
        
        $result = $db->execute("DELETE FROM categories WHERE id = ?", [$id]);
        echo json_encode(['success' => $result, 'message' => 'Category deleted']);
    }
}

// SUPPLIERS CRUD
elseif ($type === 'supplier') {
    if ($action === 'list') {
        $data = $db->fetchAll("SELECT id, name, contact, email FROM suppliers ORDER BY name");
        echo json_encode(['success' => true, 'data' => $data]);
    }
    elseif ($action === 'create') {
        $name = sanitize($_POST['name'] ?? '');
        $contact = sanitize($_POST['contact'] ?? '');
        $email = sanitize($_POST['email'] ?? '');
        
        if (!$name) {
            echo json_encode(['error' => 'Supplier name required']);
            exit();
        }
        
        $result = $db->execute(
            "INSERT INTO suppliers (name, contact, email) VALUES (?, ?, ?)",
            [$name, $contact, $email]
        );
        
        if ($result) {
            $id = $db->lastInsertId();
            echo json_encode(['success' => true, 'id' => $id, 'message' => 'Supplier created']);
        } else {
            echo json_encode(['error' => 'Failed to create supplier']);
        }
    }
    elseif ($action === 'update') {
        $id = intval($_POST['id'] ?? 0);
        $name = sanitize($_POST['name'] ?? '');
        $contact = sanitize($_POST['contact'] ?? '');
        $email = sanitize($_POST['email'] ?? '');
        
        if ($id <= 0 || !$name) {
            echo json_encode(['error' => 'Invalid data']);
            exit();
        }
        
        $result = $db->execute(
            "UPDATE suppliers SET name = ?, contact = ?, email = ? WHERE id = ?",
            [$name, $contact, $email, $id]
        );
        
        echo json_encode(['success' => $result, 'message' => 'Supplier updated']);
    }
    elseif ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['error' => 'Invalid ID']);
            exit();
        }
        
        $result = $db->execute("DELETE FROM suppliers WHERE id = ?", [$id]);
        echo json_encode(['success' => $result, 'message' => 'Supplier deleted']);
    }
}

else {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid action or type']);
}
?>
