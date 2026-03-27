<?php
/**
 * API Endpoint - Search Products by barcode or name
 * Used by POS as server-side fallback when client-side lookup misses.
 * Returns products in the same shape as the PRODS array in pos.php.
 */

session_start();
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

header('Content-Type: application/json');

if (!isLoggedIn() || (!hasRole('cashier') && !hasRole('admin') && !hasRole('manager'))) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$q = trim($_GET['q'] ?? '');
if ($q === '') {
    echo json_encode([]);
    exit();
}

try {
    // 1) Exact barcode match on main products table
    $products = $db->fetchAll(
        "SELECT p.id, p.name, p.barcode, p.price_retail, p.price_sarisar, p.price_bulk,
                p.bulk_unit, p.quantity, p.min_quantity, p.category_id, c.name AS category_name
         FROM products p
         LEFT JOIN categories c ON p.category_id = c.id
         WHERE p.active = 1 AND p.barcode = ?
         LIMIT 5",
        [$q]
    );

    // 2) Exact barcode match on product_barcodes (migration_v3)
    if (empty($products)) {
        try {
            $row = $db->fetchOne(
                "SELECT product_id FROM product_barcodes WHERE barcode = ? LIMIT 1",
                [$q]
            );
            if ($row) {
                $products = $db->fetchAll(
                    "SELECT p.id, p.name, p.barcode, p.price_retail, p.price_sarisar, p.price_bulk,
                            p.bulk_unit, p.quantity, p.min_quantity, p.category_id, c.name AS category_name
                     FROM products p
                     LEFT JOIN categories c ON p.category_id = c.id
                     WHERE p.active = 1 AND p.id = ?
                     LIMIT 1",
                    [$row['product_id']]
                );
            }
        } catch (Exception $e) { /* product_barcodes may not exist yet */ }
    }

    // 3) Name LIKE search (only if no barcode match)
    if (empty($products)) {
        $products = $db->fetchAll(
            "SELECT p.id, p.name, p.barcode, p.price_retail, p.price_sarisar, p.price_bulk,
                    p.bulk_unit, p.quantity, p.min_quantity, p.category_id, c.name AS category_name
             FROM products p
             LEFT JOIN categories c ON p.category_id = c.id
             WHERE p.active = 1 AND p.name LIKE ?
             ORDER BY p.name
             LIMIT 10",
            ['%' . $q . '%']
        );
    }

    if (empty($products)) {
        echo json_encode([]);
        exit();
    }

    // Attach extra_barcodes and tiers — same logic as pos.php
    $ids = array_column($products, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $extra_barcodes = [];
    try {
        $eb = $db->fetchAll(
            "SELECT product_id, barcode, unit_label FROM product_barcodes WHERE product_id IN ($placeholders)",
            $ids
        );
        foreach ($eb as $row) {
            $extra_barcodes[$row['product_id']][] = ['barcode' => $row['barcode'], 'unit' => $row['unit_label']];
        }
    } catch (Exception $e) { /* table may not exist yet */ }

    $extra_tiers = [];
    try {
        $et = $db->fetchAll(
            "SELECT product_id, tier_name, price, unit_label, sort_order
             FROM product_price_tiers WHERE product_id IN ($placeholders)
             ORDER BY product_id, sort_order",
            $ids
        );
        foreach ($et as $row) {
            $extra_tiers[$row['product_id']][] = $row;
        }
    } catch (Exception $e) { /* table may not exist yet */ }

    foreach ($products as &$p) {
        $pid = $p['id'];
        $p['extra_barcodes'] = $extra_barcodes[$pid] ?? [];
        if (!empty($extra_tiers[$pid])) {
            $p['tiers'] = $extra_tiers[$pid];
        } else {
            $p['tiers'] = [];
            if ($p['price_retail'] > 0) $p['tiers'][] = ['tier_name' => 'Retail', 'price' => (float)$p['price_retail'], 'unit_label' => 'pcs'];
            if (!empty($p['price_sarisar'])) $p['tiers'][] = ['tier_name' => 'Pack', 'price' => (float)$p['price_sarisar'], 'unit_label' => 'pack'];
            if (!empty($p['price_bulk']))    $p['tiers'][] = ['tier_name' => ($p['bulk_unit'] ?: 'Bulk'), 'price' => (float)$p['price_bulk'], 'unit_label' => strtolower($p['bulk_unit'] ?: 'bulk')];
        }
        // Cast numeric fields
        $p['price_retail']  = (float)$p['price_retail'];
        $p['price_sarisar'] = (float)$p['price_sarisar'];
        $p['price_bulk']    = (float)$p['price_bulk'];
        $p['quantity']      = $p['quantity'] !== null ? (int)$p['quantity'] : null;
        $p['min_quantity']  = (int)($p['min_quantity'] ?? 5);
        $p['id']            = (int)$p['id'];
        $p['category_id']   = (int)$p['category_id'];
    }
    unset($p);

    echo json_encode(array_values($products), JSON_HEX_TAG);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}
