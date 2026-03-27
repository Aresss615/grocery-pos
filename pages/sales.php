<?php
/**
 * J&J Grocery POS - Sales & Checkout
 * POS system for processing transactions
 */

session_start();
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

// Check auth
if (!isLoggedIn()) {
    redirect(BASE_URL . '/index.php');
}

$page_title = 'Point of Sale';

// Handle checkout submission (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'checkout') {
    header('Content-Type: application/json');

    $items = json_decode($_POST['items'] ?? '[]', true);
    $payment_method = sanitize($_POST['payment_method'] ?? 'cash');
    $amount_paid = floatval($_POST['amount_paid'] ?? 0);

    if (empty($items)) {
        echo json_encode(['success' => false, 'message' => 'Cart is empty']);
        exit();
    }

    // Calculate totals
    $subtotal = 0;
    foreach ($items as $item) {
        $subtotal += $item['price'] * $item['quantity'];
    }

    $tax = calculateVAT($subtotal);
    $total = calculateTotal($subtotal); // Subtotal + 12% VAT

    // Validate payment
    if ($amount_paid < $total && $payment_method === 'cash') {
        echo json_encode(['success' => false, 'message' => 'Insufficient payment']);
        exit();
    }

    // Record transaction
    try {
        $db->execute(
            "INSERT INTO sales (cashier_id, subtotal, tax_amount, total_amount, payment_method, amount_paid, change_amount) 
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [$_SESSION['user_id'], $subtotal, $tax, $total, $payment_method, $amount_paid, max(0, $amount_paid - $total)]
        );

        $sale_id = $db->lastInsertId();
        $change = max(0, $amount_paid - $total);

        // Record sale items and update stock
        foreach ($items as $item) {
            $db->execute(
                "INSERT INTO sale_items (sale_id, product_id, quantity, unit_price, subtotal) 
                 VALUES (?, ?, ?, ?, ?)",
                [$sale_id, $item['id'], $item['quantity'], $item['price'], $item['price'] * $item['quantity']]
            );

            // Decrement stock (floor at 0 — never go negative)
            $db->execute(
                "UPDATE products SET quantity = GREATEST(0, quantity - ?) WHERE id = ?",
                [$item['quantity'], $item['id']]
            );
        }

        echo json_encode([
            'success' => true,
            'sale_id' => $sale_id,
            'total' => $total,
            'change' => $change,
            'vat' => $tax
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Transaction error: ' . $e->getMessage()]);
    }
    exit();
}

// Fetch products for POS
$products = $db->fetchAll("SELECT id, name, barcode, price_retail, quantity FROM products WHERE active = 1 ORDER BY name ASC");
?>
<!DOCTYPE html>
<html lang="fil">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo APP_NAME; ?> - <?php echo $page_title; ?></title>
    <link rel="stylesheet" href="<?php echo CSS_URL; ?>/main.css">
</head>
<body class="dashboard-page">
    <?php include __DIR__ . '/../templates/navbar.php'; ?>

    <div class="pos-system">
        <!-- Left: Products -->
        <div class="pos-products">
            <div class="pos-header">
                <h2>Products</h2>
            </div>

            <div class="pos-search">
                <input type="text" id="productSearch" class="form-input" placeholder="Search product or barcode... (Press Enter)">
            </div>

            <div class="pos-grid" id="productGrid">
                <?php foreach ($products as $product): ?>
                    <button type="button" class="product-btn" 
                            onclick="addToCart(<?php echo $product['id']; ?>, '<?php echo htmlspecialchars($product['name']); ?>', <?php echo $product['price_retail']; ?>, <?php echo $product['quantity']; ?>)"
                            data-search="<?php echo strtolower($product['name'] . ' ' . $product['barcode']); ?>">
                        <div class="product-icon">📦</div>
                        <div class="product-name"><?php echo htmlspecialchars(substr($product['name'], 0, 20)); ?></div>
                        <div class="product-price"><?php echo formatCurrency($product['price_retail']); ?></div>
                        <div class="product-stock">Stock: <?php echo $product['quantity']; ?></div>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Right: Cart -->
        <div class="pos-cart">
            <div class="pos-header">
                <h2>Cart</h2>
                <button type="button" class="btn btn-outline btn-sm" onclick="clearCart()">Clear</button>
            </div>

            <div class="cart-items" id="cartItems">
                <div class="empty-cart">🛒 Cart is empty</div>
            </div>

            <!-- Summary -->
            <div class="cart-summary">
                <div class="summary-row">
                    <span>Subtotal:</span>
                    <span id="subtotal">₱0.00</span>
                </div>
                <div class="summary-row">
                    <span>VAT (12%):</span>
                    <span id="vat">₱0.00</span>
                </div>
                <div class="summary-row total">
                    <span>Total:</span>
                    <span id="total">₱0.00</span>
                </div>
            </div>

            <!-- Payment -->
            <div class="payment-section">
                <div class="form-group">
                    <label for="paymentMethod">Payment Method</label>
                    <select id="paymentMethod" class="form-input" onchange="updatePaymentUI()">
                        <option value="cash">Cash</option>
                        <option value="gcash">GCash</option>
                        <option value="card">Credit/Debit Card</option>
                    </select>
                </div>

                <div class="form-group" id="amountPaidGroup" style="display: none;">
                    <label for="amountPaid">Amount Paid (₱)</label>
                    <input type="number" id="amountPaid" class="form-input" step="0.01" min="0" onchange="calculateChange()">
                    <div id="changeInfo" style="font-size: 12px; color: var(--color-gray); margin-top: 8px;"></div>
                </div>

                <button type="button" class="btn btn-primary btn-block" onclick="processCheckout()">
                    💳 Complete Sale
                </button>

                <a href="<?php echo BASE_URL; ?>/pages/dashboard.php" class="btn btn-outline btn-block" style="margin-top: 8px;">
                    Back
                </a>
            </div>
        </div>
    </div>

    <!-- Receipt Modal -->
    <div class="modal" id="receiptModal">
        <div class="modal-content" style="max-width: 500px;">
            <div class="modal-header">
                <h2>Sales Receipt</h2>
                <button class="modal-close" onclick="closeReceipt()">×</button>
            </div>
            <pre id="receiptContent" style="padding: 20px; overflow: auto; font-family: monospace; font-size: 12px; line-height: 1.4;"></pre>
            <div style="display: flex; gap: 12px; padding: 20px; border-top: 1px solid var(--color-border);">
                <button type="button" class="btn btn-secondary" onclick="printReceipt()" style="flex: 1;">🖨️ Print</button>
                <button type="button" class="btn btn-primary" onclick="newSale()" style="flex: 1;">New Sale</button>
            </div>
        </div>
    </div>

    <style>
        .pos-system {
            display: grid;
            grid-template-columns: 1fr 380px;
            gap: 20px;
            padding: 20px;
            max-width: 1600px;
            margin: 0 auto;
        }

        .pos-products, .pos-cart {
            background: white;
            border-radius: var(--border-radius-lg);
            box-shadow: var(--shadow-sm);
            display: flex;
            flex-direction: column;
            height: calc(100vh - 120px);
        }

        .pos-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px;
            border-bottom: 1px solid var(--color-border);
        }

        .pos-header h2 {
            margin: 0;
            font-size: 18px;
        }

        .pos-search {
            padding: 12px;
        }

        .pos-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(110px, 1fr));
            gap: 10px;
            padding: 12px;
            overflow-y: auto;
            flex: 1;
        }

        .product-btn {
            background: white;
            border: 2px solid var(--color-border);
            border-radius: var(--border-radius-md);
            padding: 12px;
            text-align: center;
            cursor: pointer;
            transition: var(--transition);
            font-size: 12px;
            display: flex;
            flex-direction: column;
            gap: 6px;
            align-items: center;
        }

        .product-btn:hover {
            border-color: var(--color-primary);
            background: var(--color-accent);
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .product-icon {
            font-size: 32px;
        }

        .product-name {
            font-weight: 600;
            line-height: 1.2;
        }

        .product-price {
            color: var(--color-primary);
            font-weight: 700;
            font-size: 14px;
        }

        .product-stock {
            color: var(--color-gray);
            font-size: 11px;
        }

        .empty-cart {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100px;
            color: var(--color-gray);
            font-size: 32px;
        }

        .cart-items {
            flex: 1;
            overflow-y: auto;
            border-bottom: 1px solid var(--color-border);
        }

        .cart-item {
            padding: 12px;
            border-bottom: 1px solid var(--color-border);
            display: flex;
            justify-content: space-between;
            gap: 8px;
            font-size: 12px;
        }

        .cart-item-info {
            flex: 1;
        }

        .cart-item-name {
            font-weight: 600;
            margin-bottom: 4px;
        }

        .cart-item-subtotal {
            color: var(--color-primary);
            font-weight: 700;
        }

        .cart-controls {
            display: flex;
            gap: 4px;
            align-items: center;
        }

        .qty-input {
            width: 40px;
            padding: 4px;
            text-align: center;
            border: 1px solid var(--color-border);
            border-radius: var(--border-radius-sm);
        }

        .btn-xs {
            padding: 4px 8px;
            font-size: 10px;
            min-width: 24px;
        }

        .cart-summary {
            padding: 12px;
            background: var(--color-bg);
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 6px 0;
            font-size: 13px;
        }

        .summary-row.total {
            border-top: 2px solid var(--color-border);
            padding-top: 10px;
            font-size: 16px;
            font-weight: 700;
        }

        .payment-section {
            padding: 12px;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .form-group {
            margin-bottom: 0;
        }

        .btn-block {
            width: 100%;
        }

        @media (max-width: 1024px) {
            .pos-system {
                grid-template-columns: 1fr;
                height: auto;
            }

            .pos-products, .pos-cart {
                height: auto;
                min-height: 400px;
            }
        }
    </style>

    <script>
        let cart = [];
        const products = <?php echo json_encode($products); ?>;

        function addToCart(id, name, price, available) {
            if (available <= 0) {
                alert('Out of stock');
                return;
            }

            const item = cart.find(i => i.id === id);
            if (item) {
                if (item.qty < available) {
                    item.qty++;
                } else {
                    alert('No more stock');
                    return;
                }
            } else {
                cart.push({ id, name, price, qty: 1, available });
            }
            updateCart();
        }

        function updateCart() {
            const cartDiv = document.getElementById('cartItems');
            if (cart.length === 0) {
                cartDiv.innerHTML = '<div class="empty-cart">🛒 Cart is empty</div>';
            } else {
                cartDiv.innerHTML = cart.map(item => `
                    <div class="cart-item">
                        <div class="cart-item-info">
                            <div class="cart-item-name">${item.name}</div>
                            <div class="cart-item-subtotal">${formatCurrency(item.price * item.qty)}</div>
                        </div>
                        <div class="cart-controls">
                            <button type="button" class="btn btn-xs" onclick="changeQty(${item.id}, -1)">−</button>
                            <input type="number" value="${item.qty}" min="1" max="${item.available}" class="qty-input" 
                                   onchange="setQty(${item.id}, this.value)">
                            <button type="button" class="btn btn-xs" onclick="changeQty(${item.id}, 1)">+</button>
                            <button type="button" class="btn btn-xs btn-danger" onclick="removeFromCart(${item.id})">✕</button>
                        </div>
                    </div>
                `).join('');
            }
            updateTotals();
        }

        function changeQty(id, amount) {
            const item = cart.find(i => i.id === id);
            if (item) {
                const newQty = item.qty + amount;
                if (newQty <= 0) {
                    removeFromCart(id);
                } else if (newQty <= item.available) {
                    item.qty = newQty;
                    updateCart();
                }
            }
        }

        function setQty(id, qty) {
            const item = cart.find(i => i.id === id);
            if (item) {
                qty = parseInt(qty);
                if (qty <= 0) {
                    removeFromCart(id);
                } else if (qty <= item.available) {
                    item.qty = qty;
                    updateCart();
                }
            }
        }

        function removeFromCart(id) {
            cart = cart.filter(i => i.id !== id);
            updateCart();
        }

        function clearCart() {
            if (confirm('Clear cart?')) {
                cart = [];
                updateCart();
            }
        }

        function updateTotals() {
            const subtotal = cart.reduce((sum, item) => sum + (item.price * item.qty), 0);
            const vat = subtotal * 0.12;
            const total = subtotal + vat; // Subtotal + 12% VAT

            document.getElementById('subtotal').textContent = formatCurrency(subtotal);
            document.getElementById('vat').textContent = formatCurrency(vat);
            document.getElementById('total').textContent = formatCurrency(total);
        }

        function updatePaymentUI() {
            const method = document.getElementById('paymentMethod').value;
            document.getElementById('amountPaidGroup').style.display = method === 'cash' ? 'block' : 'none';
        }

        function calculateChange() {
            const totalText = document.getElementById('total').textContent.replace('₱', '');
            const total = parseFloat(totalText);
            const amountPaid = parseFloat(document.getElementById('amountPaid').value) || 0;
            const change = amountPaid - total;

            const changeDiv = document.getElementById('changeInfo');
            if (amountPaid > 0) {
                if (change < 0) {
                    changeDiv.textContent = `Need ₱${Math.abs(change).toFixed(2)} more`;
                    changeDiv.style.color = '#C62828';
                } else {
                    changeDiv.textContent = `Change: ₱${change.toFixed(2)}`;
                    changeDiv.style.color = '#388E3C';
                }
            }
        }

        function formatCurrency(amount) {
            return '₱' + amount.toFixed(2);
        }

        function processCheckout() {
            if (cart.length === 0) {
                alert('Cart is empty');
                return;
            }

            const method = document.getElementById('paymentMethod').value;
            const totalText = document.getElementById('total').textContent.replace('₱', '');
            const total = parseFloat(totalText);
            const amountPaid = method === 'cash' ? (parseFloat(document.getElementById('amountPaid').value) || 0) : total;

            if (amountPaid < total && method === 'cash') {
                alert('Insufficient payment');
                return;
            }

            const formData = new FormData();
            formData.append('action', 'checkout');
            formData.append('items', JSON.stringify(cart));
            formData.append('payment_method', method);
            formData.append('amount_paid', amountPaid);

            fetch(window.location.href, { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        showReceipt(data.sale_id, data.total, data.change, data.vat);
                    } else {
                        alert(data.message);
                    }
                });
        }

        function showReceipt(saleId, total, change, vat) {
            const subtotal = total - vat;
            const receipt = `RECEIPT #${String(saleId).padStart(6, '0')}
═════════════════════
Date: ${new Date().toLocaleString()}

Items:
${cart.map(i => `${i.name.substring(0,20).padEnd(20)} ${String(i.qty).padStart(2)} ${formatCurrency(i.price * i.qty)}`).join('\n')}

═════════════════════
Subtotal:          ${formatCurrency(subtotal)}
VAT (12%):         ${formatCurrency(vat)}
TOTAL:             ${formatCurrency(total)}

Payment Method: ${document.getElementById('paymentMethod').value.toUpperCase()}
Amount Paid:       ${formatCurrency(total + change)}
Change:            ${formatCurrency(change)}

═════════════════════
Thank you!`;

            document.getElementById('receiptContent').textContent = receipt;
            document.getElementById('receiptModal').classList.add('active');
        }

        function printReceipt() {
            window.print();
        }

        function newSale() {
            document.getElementById('receiptModal').classList.remove('active');
            cart = [];
            updateCart();
            document.getElementById('productSearch').value = '';
            document.getElementById('amountPaid').value = '';
        }

        function closeReceipt() {
            document.getElementById('receiptModal').classList.remove('active');
        }

        document.getElementById('productSearch').addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                const search = e.target.value.toLowerCase();
                const product = products.find(p => p.barcode === search || p.name.toLowerCase().includes(search));
                if (product) {
                    addToCart(product.id, product.name, product.price_retail, product.quantity);
                    e.target.value = '';
                }
            }
        });

        updatePaymentUI();
    </script>

    <script src="<?php echo JS_URL; ?>/main.js"></script>
</body>
</html>
