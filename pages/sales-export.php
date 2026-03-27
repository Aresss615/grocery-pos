<?php
/**
 * Sales CSV Export
 * Generates a downloadable CSV of sales transactions.
 * Access: manager or admin only.
 */

session_start();
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

if (!isLoggedIn() || (!hasRole('manager') && !hasRole('admin'))) {
    http_response_code(403);
    die('Access denied.');
}

checkSessionTimeout();

$db = new Database();

$days       = max(1, min(365, intval($_GET['days'] ?? 30)));
$since      = date('Y-m-d', strtotime("-{$days} days"));
$filename   = 'sales_export_' . date('Y-m-d') . '.csv';

// Fetch sales with cashier name and item count
$rows = $db->fetchAll(
    "SELECT
        s.id,
        s.created_at,
        u.name         AS cashier,
        s.subtotal,
        s.tax_amount,
        s.total_amount,
        s.payment_method,
        s.amount_paid,
        s.change_amount,
        s.notes,
        COUNT(si.id)   AS item_count
     FROM sales s
     JOIN users u ON s.cashier_id = u.id
     LEFT JOIN sale_items si ON si.sale_id = s.id
     WHERE s.created_at >= ?
     GROUP BY s.id
     ORDER BY s.created_at DESC",
    [$since]
);

// Log the export action
logActivity($db, 'export_sales', "Exported {$days}-day sales CSV (" . count($rows) . " rows)");

// Stream CSV
header('Content-Type: text/csv; charset=UTF-8');
header("Content-Disposition: attachment; filename=\"{$filename}\"");
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');

// BOM so Excel auto-detects UTF-8
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');

// Header row
fputcsv($out, [
    'Sale ID',
    'Date & Time',
    'Cashier',
    'Subtotal (PHP)',
    'VAT 12% (PHP)',
    'Total (PHP)',
    'Payment Method',
    'Amount Paid (PHP)',
    'Change (PHP)',
    'Item Count',
    'Notes',
]);

foreach ($rows as $row) {
    fputcsv($out, [
        str_pad($row['id'], 5, '0', STR_PAD_LEFT),
        $row['created_at'],
        $row['cashier'],
        number_format($row['subtotal'], 2, '.', ''),
        number_format($row['tax_amount'], 2, '.', ''),
        number_format($row['total_amount'], 2, '.', ''),
        ucfirst($row['payment_method']),
        number_format($row['amount_paid'], 2, '.', ''),
        number_format($row['change_amount'], 2, '.', ''),
        $row['item_count'],
        $row['notes'] ?? '',
    ]);
}

fclose($out);
exit;
