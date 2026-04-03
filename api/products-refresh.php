<?php
session_start();
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

if (!isLoggedIn()) { http_response_code(401); echo json_encode(['error'=>'Unauthorized']); exit; }

$products = $db->fetchAll(
    "SELECT p.id, p.name, p.barcode, p.price_retail, p.price_wholesale,
            p.quantity, p.min_quantity, p.category_id, c.name AS category_name
     FROM products p
     LEFT JOIN categories c ON p.category_id = c.id
     WHERE p.active = 1 ORDER BY c.name, p.name"
);

$extra_barcodes = [];
try {
    $eb = $db->fetchAll("SELECT product_id, barcode, unit_label FROM product_barcodes");
    foreach ($eb as $row) {
        $extra_barcodes[$row['product_id']][] = ['barcode'=>$row['barcode'], 'unit'=>$row['unit_label']];
    }
} catch (Exception $e) {}

$extra_tiers = [];
try {
    $et = $db->fetchAll(
        "SELECT product_id, tier_name, price, unit_label, qty_multiplier, sort_order, price_mode
         FROM product_price_tiers ORDER BY product_id, sort_order"
    );
    foreach ($et as $row) $extra_tiers[$row['product_id']][] = $row;
} catch (Exception $e) {}

foreach ($products as &$p) {
    $pid = $p['id'];
    $p['extra_barcodes'] = $extra_barcodes[$pid] ?? [];
    if (!empty($extra_tiers[$pid])) {
        $p['tiers'] = $extra_tiers[$pid];
    } else {
        $p['tiers'] = [];
        if ($p['price_retail']    > 0) $p['tiers'][] = ['tier_name'=>'Retail',    'price'=>(float)$p['price_retail'],    'unit_label'=>'pcs', 'price_mode'=>'retail'];
        if ($p['price_wholesale'] > 0) $p['tiers'][] = ['tier_name'=>'Wholesale', 'price'=>(float)$p['price_wholesale'], 'unit_label'=>'pcs', 'price_mode'=>'wholesale'];
    }
    $p['price_retail']    = (float)$p['price_retail'];
    $p['price_wholesale'] = (float)$p['price_wholesale'];
    $p['quantity']        = $p['quantity'] !== null ? (int)$p['quantity'] : null;
    $p['min_quantity']    = (int)($p['min_quantity'] ?? 5);
    $p['id']              = (int)$p['id'];
    $p['category_id']     = (int)$p['category_id'];
}
unset($p);

header('Content-Type: application/json');
echo json_encode($products, JSON_HEX_TAG);
