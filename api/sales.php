<?php
/**
 * Sales API — v4
 * Checkout: receipt numbering, per-item & transaction discounts,
 * VAT from business_settings, double-entry journal auto-generation.
 */

session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

if (!isLoggedIn() || !hasAccess('pos')) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'POST only']);
    exit;
}

// CSRF validation
if (!validateCsrf()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid request (CSRF)']);
    exit;
}

// Day-lock check: reject sales after Z-Read
$biz = getBusinessSettings();
$day_closed = $biz['day_closed'] ?? null;
if ($day_closed && $day_closed === date('Y-m-d')) {
    echo json_encode(['success' => false, 'message' => 'Day is closed. Z-Read has been generated. No more sales allowed today.']);
    exit;
}

try {
    $data = json_decode(file_get_contents('php://input'), true);

    if (empty($data['cart']) || !is_array($data['cart'])) {
        throw new Exception('Empty or invalid cart');
    }

    // Validate payment method
    $allowed_methods = ['cash', 'gcash', 'card'];
    $payment_method  = $data['payment_method'] ?? 'cash';
    if (!in_array($payment_method, $allowed_methods, true)) {
        throw new Exception('Invalid payment method');
    }

    // Price mode
    $price_mode = in_array($data['price_mode'] ?? 'retail', ['retail', 'wholesale'])
        ? ($data['price_mode'] ?? 'retail')
        : 'retail';

    // Optional customer name
    $customer_name = trim($data['customer_name'] ?? '') ?: null;

    // Transaction-level discount
    $txn_disc_type  = in_array($data['discount_type'] ?? 'none', ['none','percent','fixed'])
        ? ($data['discount_type'] ?? 'none') : 'none';
    $txn_disc_value = max(0, floatval($data['discount_value'] ?? 0));

    // Load VAT settings from business_settings table
    $biz           = getBusinessSettings($db);
    $vat_registered = (int)$biz['vat_registered'] === 1;
    $vat_inclusive  = (int)$biz['vat_inclusive'] === 1;
    $vat_rate       = (float)$biz['vat_rate'];

    // ── Server-side price recalculation ────────────────────────
    $validated_items = [];
    $items_subtotal  = 0.0;

    foreach ($data['cart'] as $item) {
        $product_id = intval($item['id'] ?? 0);
        $qty        = intval($item['qty'] ?? 0);

        if ($product_id <= 0 || $qty <= 0) {
            throw new Exception("Invalid cart item (id={$product_id}, qty={$qty})");
        }

        $product = $db->fetchOne(
            "SELECT id, name, price_retail, price_wholesale, quantity FROM products WHERE id = ? AND active = 1",
            [$product_id]
        );

        if (!$product) {
            throw new Exception("Product id={$product_id} not found or inactive");
        }

        if ($product['quantity'] !== null && $product['quantity'] < $qty) {
            throw new Exception("Insufficient stock for \"{$product['name']}\" (available: {$product['quantity']}, requested: {$qty})");
        }

        // Resolve price by mode — wholesale falls back to retail if unset
        $unit_price = floatval($product['price_retail']);
        if ($price_mode === 'wholesale' && !empty($product['price_wholesale']) && floatval($product['price_wholesale']) > 0) {
            $unit_price = floatval($product['price_wholesale']);
        }
        if ($unit_price <= 0) {
            throw new Exception("Product \"{$product['name']}\" has no price for {$price_mode} mode");
        }

        // Per-item discount
        $item_disc_type  = in_array($item['discount_type'] ?? 'none', ['none','percent','fixed'])
            ? ($item['discount_type'] ?? 'none') : 'none';
        $item_disc_value = max(0, floatval($item['discount_value'] ?? 0));
        $item_gross      = $unit_price * $qty;
        $item_disc_amt   = 0.0;

        if ($item_disc_type === 'percent' && $item_disc_value > 0) {
            $item_disc_amt = round($item_gross * ($item_disc_value / 100), 2);
        } elseif ($item_disc_type === 'fixed' && $item_disc_value > 0) {
            $item_disc_amt = min($item_disc_value, $item_gross);
        }

        $item_subtotal   = round($item_gross - $item_disc_amt, 2);
        $items_subtotal += $item_subtotal;

        $validated_items[] = [
            'product_id'   => $product_id,
            'name'         => $product['name'],
            'qty'          => $qty,
            'unit_price'   => $unit_price,
            'disc_type'    => $item_disc_type,
            'disc_value'   => $item_disc_value,
            'disc_amount'  => $item_disc_amt,
            'subtotal'     => $item_subtotal,
        ];
    }

    // Transaction-level discount
    $txn_disc_amt = 0.0;
    if ($txn_disc_type === 'percent' && $txn_disc_value > 0) {
        $txn_disc_amt = round($items_subtotal * ($txn_disc_value / 100), 2);
    } elseif ($txn_disc_type === 'fixed' && $txn_disc_value > 0) {
        $txn_disc_amt = min($txn_disc_value, $items_subtotal);
    }

    $subtotal = round($items_subtotal - $txn_disc_amt, 2);

    // VAT computation
    if (!$vat_registered) {
        $vat_amount = 0.0;
        $total      = $subtotal;
    } elseif ($vat_inclusive) {
        $vat_amount = round($subtotal * ($vat_rate / (1 + $vat_rate)), 2);
        $total      = $subtotal; // VAT already embedded
    } else {
        $vat_amount = round($subtotal * $vat_rate, 2);
        $total      = round($subtotal + $vat_amount, 2);
    }

    $amount_paid = floatval($data['amount_paid'] ?? 0);
    if ($payment_method === 'cash' && $amount_paid < $total) {
        throw new Exception('Insufficient cash (need ₱' . number_format($total, 2) . ', received ₱' . number_format($amount_paid, 2) . ')');
    }
    if ($payment_method !== 'cash') {
        $amount_paid = $total;
    }
    $change = max(0, round($amount_paid - $total, 2));

    // ── Begin transaction ──────────────────────────────────────
    $db->beginTransaction();

    // Generate atomic receipt number (SELECT FOR UPDATE — must be in transaction)
    $receipt_number = generateReceiptNumber($db);

    // Insert sale header
    if (!$db->execute(
        "INSERT INTO sales
            (cashier_id, receipt_number, customer_name, price_mode,
             subtotal, tax_amount, discount_amount, discount_type, discount_value,
             total_amount, payment_method, amount_paid, change_amount, notes, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [
            intval($_SESSION['user_id']),
            $receipt_number,
            $customer_name,
            $price_mode,
            $subtotal,
            $vat_amount,
            $txn_disc_amt,
            $txn_disc_type,
            $txn_disc_value,
            $total,
            $payment_method,
            $amount_paid,
            $change,
            'POS Sale — ' . ucfirst($price_mode) . ' mode',
            date('Y-m-d H:i:s'),
        ]
    )) {
        throw new Exception('Failed to create sale record: ' . $db->getError());
    }

    $sale_id = $db->lastInsertId();

    // Insert sale items and decrement stock
    foreach ($validated_items as $item) {
        if (!$db->execute(
            "INSERT INTO sale_items
                (sale_id, product_id, quantity, unit_price, subtotal,
                 discount_amount, discount_type, discount_value, price_tier)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $sale_id,
                $item['product_id'],
                $item['qty'],
                $item['unit_price'],
                $item['subtotal'],
                $item['disc_amount'],
                $item['disc_type'],
                $item['disc_value'],
                $price_mode,
            ]
        )) {
            throw new Exception('Failed to insert sale item: ' . $db->getError());
        }

        $db->execute(
            "UPDATE products SET quantity = GREATEST(0, quantity - ?) WHERE id = ? AND quantity IS NOT NULL",
            [$item['qty'], $item['product_id']]
        );
    }

    // ── Journal entries ────────────────────────────────────────
    $today       = date('Y-m-d');
    $user_id     = intval($_SESSION['user_id']);
    $cash_map    = [
        'cash'  => ['1010', 'Cash on Hand'],
        'gcash' => ['1011', 'Cash - GCash'],
        'card'  => ['1012', 'Cash - Card Payments'],
    ];
    [$cash_code, $cash_name] = $cash_map[$payment_method];
    $net_revenue = $vat_registered ? round($subtotal - $vat_amount, 2) : $subtotal;

    $journal_ok = true;
    $check = $db->connection->query("SHOW TABLES LIKE 'journal_entries'");
    if ($check && $check->num_rows > 0) {
        // Debit: cash account (asset up)
        $db->execute(
            "INSERT INTO journal_entries
                (entry_date, reference_type, reference_id, description, account_code, account_name, debit, credit, created_by)
             VALUES (?, 'sale', ?, ?, ?, ?, ?, 0.00, ?)",
            [$today, $sale_id, "Sale {$receipt_number}", $cash_code, $cash_name, $total, $user_id]
        );
        // Credit: Sales Revenue
        $db->execute(
            "INSERT INTO journal_entries
                (entry_date, reference_type, reference_id, description, account_code, account_name, debit, credit, created_by)
             VALUES (?, 'sale', ?, ?, '4010', 'Sales Revenue', 0.00, ?, ?)",
            [$today, $sale_id, "Sale {$receipt_number}", $net_revenue, $user_id]
        );
        // Credit: VAT Payable
        if ($vat_registered && $vat_amount > 0) {
            $db->execute(
                "INSERT INTO journal_entries
                    (entry_date, reference_type, reference_id, description, account_code, account_name, debit, credit, created_by)
                 VALUES (?, 'sale', ?, ?, '2010', 'VAT Payable', 0.00, ?, ?)",
                [$today, $sale_id, "VAT on {$receipt_number}", $vat_amount, $user_id]
            );
        }
        // Debit: Sales Discounts (if any)
        if ($txn_disc_amt > 0) {
            $db->execute(
                "INSERT INTO journal_entries
                    (entry_date, reference_type, reference_id, description, account_code, account_name, debit, credit, created_by)
                 VALUES (?, 'sale', ?, ?, '4020', 'Sales Discounts', ?, 0.00, ?)",
                [$today, $sale_id, "Discount on {$receipt_number}", $txn_disc_amt, $user_id]
            );
        }

        // Update ledger balances
        $db->execute(
            "UPDATE ledger_accounts SET balance = balance + ? WHERE account_code = ?",
            [$total, $cash_code]
        );
        $db->execute(
            "UPDATE ledger_accounts SET balance = balance + ? WHERE account_code = '4010'",
            [$net_revenue]
        );
        if ($vat_registered && $vat_amount > 0) {
            $db->execute(
                "UPDATE ledger_accounts SET balance = balance + ? WHERE account_code = '2010'",
                [$vat_amount]
            );
        }
    }

    $db->commit();

    // Activity log (outside transaction — non-critical)
    logActivity(
        $db, 'sale',
        "Sale {$receipt_number} — ₱" . number_format($total, 2) . " via {$payment_method}",
        $sale_id, 'info'
    );

    echo json_encode([
        'success'        => true,
        'sale_id'        => $sale_id,
        'receipt_number' => $receipt_number,
        'subtotal'       => $subtotal,
        'tax'            => $vat_amount,
        'total'          => $total,
        'discount'       => $txn_disc_amt,
        'payment'        => $payment_method,
        'amount_paid'    => $amount_paid,
        'change'         => $change,
        'price_mode'     => $price_mode,
        'vat_inclusive'  => $vat_inclusive,
        'vat_registered' => $vat_registered,
        'customer_name'  => $customer_name,
        'timestamp'      => date('Y-m-d H:i:s'),
    ]);

} catch (Exception $e) {
    if (isset($db) && $db->connection) {
        try { $db->rollback(); } catch (Exception $re) {}
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
