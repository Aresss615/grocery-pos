<?php
/**
 * Sales API - Process and save POS transactions
 * Handles cart checkout, creates sales records, updates inventory
 * BIR Compliant
 *
 * Changes v2:
 * - Allow admin role in addition to cashier (was cashier-only)
 * - Server-side recalculation of subtotal/VAT/total (never trust client math)
 * - DB transaction wrapping so partial failures don't corrupt data
 * - Prevent negative stock (GREATEST(0, qty - sold))
 * - Validate payment_method against allowed enum values
 * - Validate each cart item price against DB (prevent price manipulation)
 */

session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

// Check auth — cashier or admin
if (!isLoggedIn() || (!hasRole('cashier') && !hasRole('admin'))) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'POST only']);
    exit;
}

try {
    $db = new Database();
    $data = json_decode(file_get_contents('php://input'), true);

    if (empty($data['cart']) || !is_array($data['cart'])) {
        throw new Exception('Empty or invalid cart');
    }

    // Validate payment method against allowed values
    $allowed_methods = ['cash', 'gcash', 'card'];
    $payment_method = $data['payment_method'] ?? 'cash';
    if (!in_array($payment_method, $allowed_methods, true)) {
        throw new Exception('Invalid payment method');
    }

    $price_tier = $data['price_tier'] ?? 'retail';
    $allowed_tiers = ['retail', 'pack', 'wholesale'];
    if (!in_array($price_tier, $allowed_tiers, true)) {
        $price_tier = 'retail';
    }

    // Server-side recalculation — fetch prices from DB, ignore client prices
    $subtotal = 0.0;
    $validated_items = [];

    foreach ($data['cart'] as $item) {
        $product_id = intval($item['id'] ?? 0);
        $qty        = intval($item['qty'] ?? 0);

        if ($product_id <= 0 || $qty <= 0) {
            throw new Exception("Invalid cart item (id={$product_id}, qty={$qty})");
        }

        // Fetch authoritative price from DB
        $product = $db->fetchOne(
            "SELECT id, name, price_retail, price_sarisar, price_bulk, quantity FROM products WHERE id = ? AND active = 1",
            [$product_id]
        );

        if (!$product) {
            throw new Exception("Product id={$product_id} not found or inactive");
        }

        // Check sufficient stock (if tracked)
        if ($product['quantity'] !== null && $product['quantity'] < $qty) {
            throw new Exception("Insufficient stock for \"{$product['name']}\" (available: {$product['quantity']}, requested: {$qty})");
        }

        // Resolve price by tier
        $unit_price = floatval($product['price_retail']);
        if ($price_tier === 'pack' && !empty($product['price_sarisar'])) {
            $unit_price = floatval($product['price_sarisar']);
        } elseif ($price_tier === 'wholesale' && !empty($product['price_bulk'])) {
            $unit_price = floatval($product['price_bulk']);
        }

        $item_subtotal = $unit_price * $qty;
        $subtotal += $item_subtotal;

        $validated_items[] = [
            'product_id'  => $product_id,
            'qty'         => $qty,
            'unit_price'  => $unit_price,
            'subtotal'    => $item_subtotal,
        ];
    }

    // Server-side VAT + total
    $vat   = round($subtotal * VAT_RATE, 2);
    $total = round($subtotal + $vat, 2);

    $amount_paid = floatval($data['amount_paid'] ?? 0);

    // For cash payments, ensure sufficient amount is provided
    if ($payment_method === 'cash' && $amount_paid < $total) {
        throw new Exception('Insufficient cash amount (need ₱' . number_format($total, 2) . ', received ₱' . number_format($amount_paid, 2) . ')');
    }

    // For electronic payments, amount paid equals the total
    if ($payment_method !== 'cash') {
        $amount_paid = $total;
    }

    $change = max(0, $amount_paid - $total);

    // --- Begin DB transaction ---
    $db->connection->begin_transaction();

    // Insert sale header
    $sale_sql = "INSERT INTO sales (cashier_id, subtotal, tax_amount, total_amount, payment_method, amount_paid, change_amount, notes, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";

    if (!$db->execute($sale_sql, [
        intval($_SESSION['user_id']),
        $subtotal,
        $vat,
        $total,
        $payment_method,
        $amount_paid,
        $change,
        'POS Terminal Sale [' . ucfirst($price_tier) . ' Tier]',
        date('Y-m-d H:i:s')
    ])) {
        throw new Exception('Failed to create sale record: ' . $db->getError());
    }

    $sale_id = $db->lastInsertId();

    // Insert sale items and update stock
    foreach ($validated_items as $item) {
        $item_sql = "INSERT INTO sale_items (sale_id, product_id, quantity, unit_price, subtotal)
                     VALUES (?, ?, ?, ?, ?)";

        if (!$db->execute($item_sql, [
            $sale_id,
            $item['product_id'],
            $item['qty'],
            $item['unit_price'],
            $item['subtotal']
        ])) {
            throw new Exception('Failed to insert sale item: ' . $db->getError());
        }

        // Decrement stock — GREATEST(0, ...) prevents negative inventory
        $db->execute(
            "UPDATE products SET quantity = GREATEST(0, quantity - ?) WHERE id = ? AND quantity IS NOT NULL",
            [$item['qty'], $item['product_id']]
        );
    }

    // Commit transaction
    $db->connection->commit();

    // Log the activity
    if (function_exists('logActivity')) {
        logActivity($db, 'sale', "Sale #{$sale_id} processed — total ₱" . number_format($total, 2) . " via {$payment_method}");
    }

    echo json_encode([
        'success'     => true,
        'sale_id'     => $sale_id,
        'subtotal'    => $subtotal,
        'tax'         => $vat,
        'total'       => $total,
        'payment'     => $payment_method,
        'amount_paid' => $amount_paid,
        'change'      => $change,
        'price_tier'  => $price_tier,
        'timestamp'   => date('Y-m-d H:i:s')
    ]);

} catch (Exception $e) {
    // Roll back any partial writes
    if (isset($db) && $db->connection) {
        $db->connection->rollback();
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
