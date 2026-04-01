<?php
/**
 * J&J Grocery POS — Refunds API v4
 * GET  ?receipt=JJ-000042        → look up sale + items by receipt number
 * POST action=process_refund     → create refund record, journal entries, mark sale
 * Requires manager_portal access.
 */

session_start();
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['error' => 'Unauthorized']); exit;
}
if (!hasAccess('manager_portal')) {
    echo json_encode(['error' => 'Access denied']); exit;
}

$db   = new Database();
$user = getCurrentUser();

// ── GET: Look up sale by receipt number ───────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $receipt = trim($_GET['receipt'] ?? '');
    if (!$receipt) {
        echo json_encode(['error' => 'Receipt number required']); exit;
    }

    $sale = $db->fetchOne(
        "SELECT s.*, u.name AS cashier_name
           FROM sales s
           LEFT JOIN users u ON s.cashier_id = u.id
          WHERE s.receipt_number = ?
          LIMIT 1",
        [$receipt]
    );

    if (!$sale) {
        echo json_encode(['error' => 'Receipt not found']); exit;
    }
    if ($sale['voided']) {
        echo json_encode(['error' => 'Cannot refund a voided sale']); exit;
    }

    // Check if already fully refunded
    $already_refunded = floatval($sale['refund_amount'] ?? 0);
    $total            = floatval($sale['total_amount']);
    if ($already_refunded >= $total) {
        echo json_encode(['error' => 'This sale has already been fully refunded']); exit;
    }

    $items = $db->fetchAll(
        "SELECT si.*, p.name AS product_name, p.barcode
           FROM sale_items si
           LEFT JOIN products p ON si.product_id = p.id
          WHERE si.sale_id = ?
          ORDER BY si.id ASC",
        [$sale['id']]
    );

    $sale['items']            = $items;
    $sale['already_refunded'] = $already_refunded;
    $sale['refundable']       = round($total - $already_refunded, 2);

    echo json_encode(['sale' => $sale]);
    exit;
}

// ── POST: Process refund ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf(true);

    $action        = $_POST['action'] ?? '';
    $sale_id       = intval($_POST['sale_id']      ?? 0);
    $reason        = trim($_POST['reason']          ?? '');
    $refund_amount = floatval($_POST['refund_amount'] ?? 0);
    $items_json    = $_POST['items']               ?? '[]';

    if ($action !== 'process_refund') {
        echo json_encode(['error' => 'Unknown action']); exit;
    }
    if (!$sale_id || !$reason || $refund_amount <= 0) {
        echo json_encode(['error' => 'Missing required fields (sale_id, reason, refund_amount)']); exit;
    }

    $sale = $db->fetchOne("SELECT * FROM sales WHERE id = ?", [$sale_id]);
    if (!$sale) {
        echo json_encode(['error' => 'Sale not found']); exit;
    }
    if ($sale['voided']) {
        echo json_encode(['error' => 'Cannot refund a voided sale']); exit;
    }

    $already_refunded = floatval($sale['refund_amount'] ?? 0);
    $max_refundable   = round(floatval($sale['total_amount']) - $already_refunded, 2);
    if ($refund_amount > $max_refundable) {
        echo json_encode(['error' => "Refund amount ₱" . number_format($refund_amount, 2) . " exceeds refundable balance ₱" . number_format($max_refundable, 2)]); exit;
    }

    $db->beginTransaction();
    try {
        // ── 1. Insert refund record ───────────────────────────
        $db->execute(
            "INSERT INTO refunds
                (original_sale_id, receipt_number, refund_amount, reason, items, processed_by)
             VALUES (?, ?, ?, ?, ?, ?)",
            [$sale_id, $sale['receipt_number'], $refund_amount, $reason, $items_json, $user['id']]
        );

        // ── 2. Mark sale as refunded ──────────────────────────
        $new_total = round($already_refunded + $refund_amount, 2);
        $is_full   = ($new_total >= floatval($sale['total_amount'])) ? 1 : 0;
        $db->execute(
            "UPDATE sales SET refunded = ?, refund_amount = refund_amount + ? WHERE id = ?",
            [$is_full, $refund_amount, $sale_id]
        );

        // ── 3. Auto journal entries (if table exists) ─────────
        $je_exists = $db->fetchOne("SHOW TABLES LIKE 'journal_entries'");
        if ($je_exists) {
            $biz          = getBusinessSettings($db);
            $vat_reg      = (bool)($biz['vat_registered'] ?? true);
            $vat_rate     = floatval($biz['vat_rate'] ?? 0.12);
            $vat_inc      = (bool)($biz['vat_inclusive'] ?? true);
            $today_date   = date('Y-m-d');

            $cash_acc = match($sale['payment_method'] ?? 'cash') {
                'gcash' => ['1011', 'Cash - GCash'],
                'card'  => ['1012', 'Cash - Card Payments'],
                default => ['1010', 'Cash on Hand'],
            };

            if ($vat_reg && $refund_amount > 0) {
                if ($vat_inc) {
                    $vat_amt = round($refund_amount * $vat_rate / (1 + $vat_rate), 2);
                } else {
                    $vat_amt = round($refund_amount - ($refund_amount / (1 + $vat_rate)), 2);
                }
                $net_amt = round($refund_amount - $vat_amt, 2);
            } else {
                $net_amt = $refund_amount;
                $vat_amt = 0;
            }

            $desc = "Refund on " . ($sale['receipt_number'] ?? "Sale #$sale_id");

            // Debit: Sales Revenue (reverse the credit)
            $db->execute(
                "INSERT INTO journal_entries
                    (entry_date, reference_type, reference_id, description, account_code, account_name, debit, credit, created_by)
                 VALUES (?, 'refund', ?, ?, '4010', 'Sales Revenue', ?, 0, ?)",
                [$today_date, $sale_id, $desc, $net_amt, $user['id']]
            );

            // Debit: VAT Payable (reverse the credit)
            if ($vat_amt > 0) {
                $db->execute(
                    "INSERT INTO journal_entries
                        (entry_date, reference_type, reference_id, description, account_code, account_name, debit, credit, created_by)
                     VALUES (?, 'refund', ?, ?, '2010', 'VAT Payable', ?, 0, ?)",
                    [$today_date, $sale_id, $desc, $vat_amt, $user['id']]
                );
            }

            // Credit: Cash account (money going back to customer)
            $db->execute(
                "INSERT INTO journal_entries
                    (entry_date, reference_type, reference_id, description, account_code, account_name, debit, credit, created_by)
                 VALUES (?, 'refund', ?, ?, ?, ?, 0, ?, ?)",
                [$today_date, $sale_id, $desc, $cash_acc[0], $cash_acc[1], $refund_amount, $user['id']]
            );

            // Credit: Sales Returns & Refunds (4030)
            $db->execute(
                "INSERT INTO journal_entries
                    (entry_date, reference_type, reference_id, description, account_code, account_name, debit, credit, created_by)
                 VALUES (?, 'refund', ?, ?, '4030', 'Sales Returns & Refunds', 0, ?, ?)",
                [$today_date, $sale_id, $desc, $refund_amount, $user['id']]
            );

            // Update ledger balances
            $db->execute("UPDATE ledger_accounts SET balance = balance - ? WHERE account_code = '4010'", [$net_amt]);
            if ($vat_amt > 0) {
                $db->execute("UPDATE ledger_accounts SET balance = balance - ? WHERE account_code = '2010'", [$vat_amt]);
            }
            $db->execute("UPDATE ledger_accounts SET balance = balance - ? WHERE account_code = ?", [$refund_amount, $cash_acc[0]]);
            $db->execute("UPDATE ledger_accounts SET balance = balance + ? WHERE account_code = '4030'", [$refund_amount]);
        }

        // ── 4. Log activity ───────────────────────────────────
        logActivity(
            $db, 'refund_processed',
            "Refund of ₱" . number_format($refund_amount, 2) . " on receipt " . ($sale['receipt_number'] ?? "#$sale_id") . ". Reason: $reason",
            $sale_id, 'critical'
        );

        $db->commit();
        echo json_encode([
            'success' => true,
            'message' => "Refund of ₱" . number_format($refund_amount, 2) . " processed successfully.",
            'refund_amount' => $refund_amount,
        ]);
    } catch (Exception $e) {
        $db->rollback();
        echo json_encode(['error' => 'Refund failed: ' . $e->getMessage()]);
    }
    exit;
}

echo json_encode(['error' => 'Method not allowed']);
