<?php
/**
 * Create Mock Sales Data
 * Run this file once to populate the database with sample sales data
 * Access: http://localhost/grocery-pos/create-mock-sales.php
 */

require_once __DIR__ . '/config/database.php';

$db = new Database();

// Check if sales already exist
$existingSales = $db->fetchOne("SELECT COUNT(*) as count FROM sales");
echo "<p style='color: #666; font-family: Arial;'>ℹ️ Current sales in database: <strong>" . $existingSales['count'] . "</strong></p>";

// Get all products
$products = $db->fetchAll("SELECT id, name, barcode, price_retail, price_sarisar, price_bulk FROM products WHERE active = 1");

if (empty($products)) {
    die("<p style='color: red; font-family: Arial;'>❌ No products found in database. Please add products first.</p>");
}

// Get a cashier user (or create test user)
$cashier = $db->fetchOne("SELECT id FROM users WHERE role = 'cashier' LIMIT 1");
if (!$cashier) {
    $cashier = $db->fetchOne("SELECT id FROM users WHERE role = 'admin' LIMIT 1");
}
$cashier_id = $cashier['id'] ?? 1;

// Payment methods
$payment_methods = ['cash', 'gcash', 'maya', 'card'];
$price_tiers = ['retail', 'pack', 'wholesale'];

echo "<!DOCTYPE html>
<html>
<head>
    <title>Creating Mock Sales Data...</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 800px; margin: 50px auto; padding: 20px; }
        .success { color: green; }
        .info { color: blue; }
        h1 { color: #E53935; }
    </style>
</head>
<body>
<h1>📊 Generating Mock Sales Data...</h1>
";

$sales_created = 0;
$items_created = 0;

// Create sales for the past 90 days
for ($day = 90; $day >= 0; $day--) {
    $date = date('Y-m-d', strtotime("-$day days"));
    
    // Random number of sales per day (3-15)
    $num_sales = rand(3, 15);
    
    for ($i = 0; $i < $num_sales; $i++) {
        // Random time during business hours (8 AM - 8 PM)
        $hour = rand(8, 20);
        $minute = rand(0, 59);
        $second = rand(0, 59);
        $sale_time = "$date $hour:$minute:$second";
        
        // Random payment method
        $payment_method = $payment_methods[array_rand($payment_methods)];
        
        // Start building sale
        $sale_items = [];
        $sale_subtotal = 0;
        $num_items = rand(1, 8); // 1-8 items per sale
        
        for ($j = 0; $j < $num_items; $j++) {
            $product = $products[array_rand($products)];
            $quantity = rand(1, 10);
            $tier = $price_tiers[array_rand($price_tiers)];
            
            // Price based on tier (fallback to retail if tier price is not set)
            if ($tier === 'retail') {
                $unit_price = $product['price_retail'];
            } elseif ($tier === 'pack') {
                $unit_price = $product['price_sarisar'] ?: $product['price_retail'];
            } else {
                $unit_price = $product['price_bulk'] ?: $product['price_retail'];
            }
            
            // Ensure unit_price is not null or 0
            $unit_price = floatval($unit_price) ?: floatval($product['price_retail']);
            
            $item_total = $unit_price * $quantity;
            $sale_subtotal += $item_total;
            
            $sale_items[] = [
                'product_id' => $product['id'],
                'product_name' => $product['name'],
                'quantity' => $quantity,
                'unit_price' => $unit_price,
                'subtotal' => $item_total,
                'price_tier' => ucfirst($tier)
            ];
        }
        
        // Calculate tax (12% VAT)
        $vat_rate = 0.12;
        $vatable_amount = $sale_subtotal / (1 + $vat_rate);
        $vat_amount = $sale_subtotal - $vatable_amount;
        
        // Insert sale
        $db->execute("INSERT INTO sales (
            cashier_id, 
            payment_method, 
            subtotal, 
            tax_amount, 
            total_amount, 
            amount_paid, 
            change_amount, 
            notes, 
            created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)", [
            $cashier_id,
            $payment_method,
            $vatable_amount,
            $vat_amount,
            $sale_subtotal,
            $sale_subtotal + rand(0, 500), // amount paid (sometimes with change)
            rand(0, 100), // change
            'Mock sale generated for testing',
            $sale_time
        ]);
        
        $sale_id = $db->lastInsertId();
        
        // Insert sale items
        foreach ($sale_items as $item) {
            $db->execute("INSERT INTO sale_items (
                sale_id, 
                product_id, 
                quantity, 
                unit_price, 
                subtotal
            ) VALUES (?, ?, ?, ?, ?)", [
                $sale_id,
                $item['product_id'],
                $item['quantity'],
                $item['unit_price'],
                $item['subtotal']
            ]);
            $items_created++;
        }
        
        $sales_created++;
    }
}

echo "<p class='success'>✅ Successfully created <strong>$sales_created sales</strong> with <strong>$items_created items</strong>!</p>";
echo "<p class='info'>📅 Sales data spans the last 90 days</p>";
echo "<p class='info'>💳 Payment methods: Cash, GCash, Maya, Card</p>";
echo "<p class='info'>🏷️ Price tiers: Retail, Pack (Sari-Sari), Wholesale</p>";
echo "<br><p><a href='/grocery-pos/pages/dashboard.php' style='background: #E53935; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;'>🏠 Go to Dashboard</a></p>";
echo "<p><a href='/grocery-pos/pages/sales-report.php'>📊 View Sales Report</a></p>";
echo "</body></html>";
?>
