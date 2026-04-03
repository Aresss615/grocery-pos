<?php
session_start();
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

if (!isLoggedIn() || !hasAccess('pos')) { http_response_code(403); echo json_encode(['error'=>'Forbidden']); exit; }

header('Content-Type: application/json');
$db    = new Database();
$uid   = (int)$_SESSION['user_id'];
$today = date('Y-m-d');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf(true);
    $declared = (float)($_POST['declared_cash'] ?? 0);
    $notes    = sanitize($_POST['notes'] ?? '');

    $totals = $db->fetchOne(
        "SELECT COALESCE(SUM(CASE WHEN payment_method='cash'  THEN total_amount ELSE 0 END),0) AS cash_sales,
                COALESCE(SUM(CASE WHEN payment_method='gcash' THEN total_amount ELSE 0 END),0) AS gcash_sales,
                COALESCE(SUM(CASE WHEN payment_method='card'  THEN total_amount ELSE 0 END),0) AS card_sales,
                MIN(created_at) AS shift_start
         FROM sales WHERE DATE(created_at)=? AND cashier_id=? AND voided=0",
        [$today, $uid], "si"
    );

    $expected = (float)($totals['cash_sales'] ?? 0);
    $user     = getCurrentUser();

    $db->execute(
        "INSERT INTO shift_closures (cashier_id, cashier_name, shift_start, shift_end, expected_cash, declared_cash, gcash_total, card_total, notes)
         VALUES (?, ?, ?, NOW(), ?, ?, ?, ?, ?)",
        [$uid, $user['name'], $totals['shift_start'] ?? date('Y-m-d H:i:s'),
         $expected, $declared, $totals['gcash_sales']??0, $totals['card_sales']??0, $notes],
        "issdddds"
    );

    logActivity($db, 'shift_close', "Declared: {$declared}, Expected: {$expected}", null, 'info');

    $biz      = getBusinessSettings($db);
    $variance = $declared - $expected;
    $sym      = htmlspecialchars($biz['currency_symbol'] ?? '&#8369;');
    $name     = htmlspecialchars($biz['business_name'] ?? 'J&J Grocery');
    $uname    = htmlspecialchars($user['name'] ?? '');
    $varSign  = $variance >= 0 ? '+' : '';

    $printHtml = '<!DOCTYPE html><html><head><style>'
        . 'body{font-family:monospace;font-size:12px;width:280px;margin:0 auto;padding:10px}'
        . '.c{text-align:center}.b{font-weight:bold}.line{border-top:1px dashed #000;margin:4px 0}'
        . '.row{display:flex;justify-content:space-between}'
        . '</style></head><body><script>window.onload=()=>{window.print()}<\/script>'
        . "<div class='c b'>{$name}</div>"
        . "<div class='c'>END OF SHIFT REPORT</div>"
        . "<div class='line'></div>"
        . "<div class='row'><span>Date:</span><span>{$today}</span></div>"
        . "<div class='row'><span>Cashier:</span><span>{$uname}</span></div>"
        . "<div class='line'></div>"
        . "<div class='row'><span>Cash Sales:</span><span>{$sym}" . number_format($totals['cash_sales']??0,2) . "</span></div>"
        . "<div class='row'><span>GCash Sales:</span><span>{$sym}" . number_format($totals['gcash_sales']??0,2) . "</span></div>"
        . "<div class='row'><span>Card Sales:</span><span>{$sym}" . number_format($totals['card_sales']??0,2) . "</span></div>"
        . "<div class='line'></div>"
        . "<div class='row b'><span>Cash Declared:</span><span>{$sym}" . number_format($declared,2) . "</span></div>"
        . "<div class='row'><span>Variance:</span><span>{$sym}{$varSign}" . number_format($variance,2) . "</span></div>"
        . "<div class='line'></div>"
        . "<div class='c' style='margin-top:10px'>Cashier signature: ___________</div>"
        . "<div class='c'>Supervisor: ___________</div>"
        . '</body></html>';

    echo json_encode(['success' => true, 'print_html' => $printHtml]);
    exit;
}

// GET: today's totals for this cashier
$totals = $db->fetchOne(
    "SELECT COALESCE(SUM(CASE WHEN payment_method='cash'  THEN total_amount ELSE 0 END),0) AS cash_sales,
            COALESCE(SUM(CASE WHEN payment_method='gcash' THEN total_amount ELSE 0 END),0) AS gcash_sales,
            COALESCE(SUM(CASE WHEN payment_method='card'  THEN total_amount ELSE 0 END),0) AS card_sales
     FROM sales WHERE DATE(created_at)=? AND cashier_id=? AND voided=0",
    [$today, $uid], "si"
);
echo json_encode($totals ?? ['cash_sales'=>0,'gcash_sales'=>0,'card_sales'=>0]);
