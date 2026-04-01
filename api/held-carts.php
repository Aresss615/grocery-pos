<?php
/**
 * Held Carts API
 * GET  — list held carts for current cashier
 * POST action=hold   — save current cart to pos_held_carts
 * POST action=resume — retrieve + delete a held cart
 * POST action=delete — delete a held cart without resuming
 *
 * Max 3 held carts per cashier.
 */

session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

if (!isLoggedIn() || !hasAccess('pos')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$cashier_id = intval($_SESSION['user_id']);

// ── GET — list held carts ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $rows = $db->fetchAll(
            "SELECT id, label, price_mode, cart_data, customer_name,
                    DATE_FORMAT(held_at, '%h:%i %p') AS held_at
             FROM pos_held_carts
             WHERE cashier_id = ?
             ORDER BY held_at ASC",
            [$cashier_id]
        );
        // Decode JSON cart_data for JS
        foreach ($rows as &$r) {
            $r['cart_data'] = json_decode($r['cart_data'] ?? '[]', true) ?: [];
        }
        unset($r);
        echo json_encode(['success' => true, 'carts' => $rows]);
    } catch (Exception $e) {
        echo json_encode(['success' => true, 'carts' => []]);
    }
    exit;
}

// ── POST ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'GET or POST only']);
    exit;
}

// Validate CSRF
if (!validateCsrf()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

$data   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $data['action'] ?? '';

try {
    if ($action === 'hold') {
        // Check max 3
        $count = $db->fetchOne(
            "SELECT COUNT(*) AS cnt FROM pos_held_carts WHERE cashier_id = ?",
            [$cashier_id]
        );
        if ((int)($count['cnt'] ?? 0) >= 3) {
            echo json_encode(['success' => false, 'message' => 'Maximum 3 held carts reached']);
            exit;
        }

        $label         = substr(trim($data['label'] ?? 'Held Cart'), 0, 50);
        $price_mode    = in_array($data['price_mode'] ?? 'retail', ['retail','wholesale']) ? $data['price_mode'] : 'retail';
        $cart          = $data['cart'] ?? [];
        $txn_discount  = $data['txn_discount'] ?? ['type'=>'none','value'=>0];
        $customer_name = substr(trim($data['customer_name'] ?? ''), 0, 100) ?: null;

        // Store cart + txn_discount together so resume can restore everything
        $cart_data = json_encode([
            'items'        => $cart,
            'txn_discount' => $txn_discount,
        ]);

        $db->execute(
            "INSERT INTO pos_held_carts (cashier_id, label, price_mode, cart_data, customer_name)
             VALUES (?, ?, ?, ?, ?)",
            [$cashier_id, $label, $price_mode, $cart_data, $customer_name]
        );

        echo json_encode(['success' => true, 'id' => $db->lastInsertId()]);

    } elseif ($action === 'resume') {
        $id = intval($data['id'] ?? 0);
        $row = $db->fetchOne(
            "SELECT * FROM pos_held_carts WHERE id = ? AND cashier_id = ?",
            [$id, $cashier_id]
        );
        if (!$row) {
            echo json_encode(['success' => false, 'message' => 'Held cart not found']);
            exit;
        }

        $raw = json_decode($row['cart_data'] ?? '{}', true) ?: [];
        // Support both old flat array and new nested format
        $cart         = $raw['items']        ?? (is_array($raw) && isset($raw[0]) ? $raw : []);
        $txn_discount = $raw['txn_discount'] ?? ['type' => 'none', 'value' => 0];

        $db->execute("DELETE FROM pos_held_carts WHERE id = ? AND cashier_id = ?", [$id, $cashier_id]);

        echo json_encode([
            'success'       => true,
            'cart'          => $cart,
            'txn_discount'  => $txn_discount,
            'price_mode'    => $row['price_mode'] ?? 'retail',
            'customer_name' => $row['customer_name'] ?? '',
        ]);

    } elseif ($action === 'delete') {
        $id = intval($data['id'] ?? 0);
        $db->execute(
            "DELETE FROM pos_held_carts WHERE id = ? AND cashier_id = ?",
            [$id, $cashier_id]
        );
        echo json_encode(['success' => true]);

    } else {
        echo json_encode(['success' => false, 'message' => 'Unknown action']);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
