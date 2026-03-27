<?php
/**
 * Sales Analytics API
 * Returns sales data for analytics and charts
 */

session_start();
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

// Check auth - manager/admin only
if (!isLoggedIn() || (!hasRole('manager') && !hasRole('admin'))) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

header('Content-Type: application/json');

$db = new Database();
$action = $_GET['action'] ?? '';

// Top selling products
if ($action === 'top_products') {
    $days = intval($_GET['days'] ?? 30);
    $data = $db->fetchAll("
        SELECT 
            p.id,
            p.name,
            p.barcode,
            SUM(si.quantity) as total_qty,
            SUM(si.subtotal) as total_amount,
            COUNT(DISTINCT si.sale_id) as num_sales,
            ROUND(AVG(si.unit_price), 2) as avg_price
        FROM sale_items si
        JOIN sales s ON si.sale_id = s.id
        JOIN products p ON si.product_id = p.id
        WHERE s.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
        GROUP BY p.id
        ORDER BY total_qty DESC
        LIMIT 15
    ", [$days]);
    
    echo json_encode(['success' => true, 'data' => $data]);
}

// Daily sales trend
else if ($action === 'daily_sales') {
    $days = intval($_GET['days'] ?? 30);
    $data = $db->fetchAll("
        SELECT 
            DATE(created_at) as sale_date,
            COUNT(*) as num_transactions,
            SUM(total_amount) as daily_total,
            SUM(tax_amount) as daily_tax
        FROM sales
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
        GROUP BY DATE(created_at)
        ORDER BY sale_date ASC
    ", [$days]);
    
    echo json_encode(['success' => true, 'data' => $data]);
}

// Monthly sales comparison
else if ($action === 'monthly_sales') {
    $months = intval($_GET['months'] ?? 12);
    $data = $db->fetchAll("
        SELECT 
            DATE_FORMAT(created_at, '%Y-%m') as month,
            COUNT(*) as num_transactions,
            SUM(total_amount) as monthly_total,
            ROUND(AVG(total_amount), 2) as avg_transaction
        FROM sales
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? MONTH)
        GROUP BY DATE_FORMAT(created_at, '%Y-%m')
        ORDER BY month ASC
    ", [$months]);
    
    echo json_encode(['success' => true, 'data' => $data]);
}

// Product sales over time
else if ($action === 'product_timeline') {
    $product_id = intval($_GET['product_id'] ?? 0);
    $months = intval($_GET['months'] ?? 12);
    
    if ($product_id <= 0) {
        echo json_encode(['error' => 'Invalid product ID']);
        exit();
    }
    
    $data = $db->fetchAll("
        SELECT 
            DATE_FORMAT(s.created_at, '%Y-%m') as month,
            SUM(si.quantity) as qty_sold,
            SUM(si.subtotal) as revenue,
            COUNT(*) as num_sales
        FROM sale_items si
        JOIN sales s ON si.sale_id = s.id
        WHERE si.product_id = ? 
        AND s.created_at >= DATE_SUB(NOW(), INTERVAL ? MONTH)
        GROUP BY DATE_FORMAT(s.created_at, '%Y-%m')
        ORDER BY month ASC
    ", [$product_id, $months]);
    
    echo json_encode(['success' => true, 'data' => $data]);
}

// Category breakdown
else if ($action === 'category_breakdown') {
    $days = intval($_GET['days'] ?? 30);
    $data = $db->fetchAll("
        SELECT 
            c.id,
            c.name as category,
            COUNT(*) as num_items_sold,
            SUM(si.quantity) as total_qty,
            SUM(si.subtotal) as total_revenue,
            ROUND(SUM(si.subtotal) / SUM(s.total_amount) * 100, 2) as percent_of_sales
        FROM sale_items si
        JOIN sales s ON si.sale_id = s.id
        JOIN products p ON si.product_id = p.id
        LEFT JOIN categories c ON p.category_id = c.id
        WHERE s.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
        GROUP BY c.id, c.name
        ORDER BY total_revenue DESC
    ", [$days]);
    
    echo json_encode(['success' => true, 'data' => $data]);
}

// Supplier breakdown
else if ($action === 'supplier_breakdown') {
    $days = intval($_GET['days'] ?? 30);
    $data = $db->fetchAll("
        SELECT 
            supp.id,
            supp.name as supplier,
            COUNT(*) as num_items_sold,
            SUM(si.quantity) as total_qty,
            SUM(si.subtotal) as total_revenue,
            ROUND(SUM(si.subtotal) / SUM(s.total_amount) * 100, 2) as percent_of_sales
        FROM sale_items si
        JOIN sales s ON si.sale_id = s.id
        JOIN products p ON si.product_id = p.id
        LEFT JOIN suppliers supp ON p.supplier_id = supp.id
        WHERE s.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
        GROUP BY supp.id, supp.name
        ORDER BY total_revenue DESC
    ", [$days]);
    
    echo json_encode(['success' => true, 'data' => $data]);
}

// Payment method breakdown
else if ($action === 'payment_breakdown') {
    $days = intval($_GET['days'] ?? 30);
    $data = $db->fetchAll("
        SELECT 
            payment_method,
            COUNT(*) as num_transactions,
            SUM(total_amount) as total_amount,
            ROUND(AVG(total_amount), 2) as avg_amount
        FROM sales
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
        GROUP BY payment_method
        ORDER BY total_amount DESC
    ", [$days]);
    
    echo json_encode(['success' => true, 'data' => $data]);
}

// Pricing tier breakdown (retail, pack, wholesale)
else if ($action === 'pricing_tier_breakdown') {
    $days = intval($_GET['days'] ?? 30);
    
    $data = $db->fetchAll("
        SELECT 
            CASE 
                WHEN notes LIKE '%Retail%' THEN 'Retail'
                WHEN notes LIKE '%Pack%' THEN 'Pack (Sari-Sari)'
                WHEN notes LIKE '%Wholesale%' THEN 'Wholesale'
                ELSE 'Unknown'
            END as tier,
            COUNT(*) as num_transactions,
            SUM(total_amount) as tier_revenue,
            ROUND(AVG(total_amount), 2) as avg_transaction,
            SUM(tax_amount) as tier_tax,
            COUNT(DISTINCT DATE(created_at)) as days_active
        FROM sales
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
        GROUP BY tier
        ORDER BY tier_revenue DESC
    ", [$days]);
    
    echo json_encode(['success' => true, 'data' => $data]);
}

// Summary stats
else if ($action === 'summary') {
    $days = intval($_GET['days'] ?? 30);
    
    $summary = $db->fetchOne("
        SELECT 
            COUNT(*) as total_transactions,
            SUM(total_amount) as total_sales,
            SUM(tax_amount) as total_tax,
            ROUND(AVG(total_amount), 2) as avg_transaction,
            MAX(total_amount) as highest_transaction,
            MIN(total_amount) as lowest_transaction,
            COUNT(DISTINCT DATE(created_at)) as days_counted
        FROM sales
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
    ", [$days]);
    
    echo json_encode(['success' => true, 'data' => $summary]);
}

else {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid action']);
}
?>
