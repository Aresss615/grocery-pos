<?php
/**
 * J&J Grocery POS - Products Management
 * Admin-only product CRUD with multiple pricing tiers and multiple barcodes
 */

session_start();
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

if (!isLoggedIn() || !hasAccess('products')) {
    redirect(BASE_URL . '/pages/dashboard.php');
}

checkSessionTimeout();

$page_title = 'Product Management';

$categories = $db->fetchAll("SELECT id, name FROM categories ORDER BY name ASC");
$suppliers  = $db->fetchAll("SELECT id, name FROM suppliers ORDER BY name ASC");

// ── POST handler ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $action = $_POST['action'] ?? '';

    // Helper: sync product_price_tiers rows for a product
    $syncTiers = function(int $product_id) use ($db) {
        $names       = $_POST['tier_name']       ?? [];
        $prices      = $_POST['tier_price']       ?? [];
        $units       = $_POST['tier_unit_label']  ?? [];
        $multipliers = $_POST['tier_qty_mult']    ?? [];
        $modes       = $_POST['tier_mode']        ?? [];

        $db->execute("DELETE FROM product_price_tiers WHERE product_id = ?", [$product_id]);

        $retail_synced    = false;
        $wholesale_synced = false;
        foreach ($names as $i => $raw_name) {
            $name  = trim($raw_name);
            $price = floatval($prices[$i] ?? 0);
            $unit  = trim($units[$i] ?? 'pcs') ?: 'pcs';
            $mult  = floatval($multipliers[$i] ?? 1) ?: 1;
            $mode  = in_array($modes[$i] ?? '', ['retail','wholesale','both']) ? $modes[$i] : 'both';
            if ($name === '' || $price <= 0) continue;

            $db->execute(
                "INSERT INTO product_price_tiers (product_id, tier_name, price, unit_label, qty_multiplier, sort_order, price_mode) VALUES (?, ?, ?, ?, ?, ?, ?)",
                [$product_id, $name, $price, $unit, $mult, $i + 1, $mode]
            );

            // Sync first retail/both tier → products.price_retail
            if (!$retail_synced && in_array($mode, ['retail','both'])) {
                $db->execute("UPDATE products SET price_retail = ? WHERE id = ?", [$price, $product_id]);
                $retail_synced = true;
            }
            // Sync first wholesale/both tier → products.price_wholesale
            if (!$wholesale_synced && in_array($mode, ['wholesale','both'])) {
                $db->execute("UPDATE products SET price_wholesale = ? WHERE id = ?", [$price, $product_id]);
                $wholesale_synced = true;
            }
        }
    };

    // Helper: sync product_barcodes rows for a product (primary + extras)
    $syncBarcodes = function(int $product_id, string $primary_barcode) use ($db) {
        $db->execute("DELETE FROM product_barcodes WHERE product_id = ?", [$product_id]);

        $seen = [];
        // Always re-insert primary
        if ($primary_barcode !== '') {
            $db->execute(
                "INSERT IGNORE INTO product_barcodes (product_id, barcode, unit_label, qty_multiplier) VALUES (?, ?, 'pcs', 1)",
                [$product_id, $primary_barcode]
            );
            $seen[$primary_barcode] = true;
        }

        // Extra barcodes from textarea (one per line)
        $extra_raw = $_POST['extra_barcodes'] ?? '';
        $lines = array_filter(array_map('trim', explode("\n", $extra_raw)));
        foreach ($lines as $bc) {
            if ($bc === '' || isset($seen[$bc])) continue;
            $db->execute(
                "INSERT IGNORE INTO product_barcodes (product_id, barcode, unit_label, qty_multiplier) VALUES (?, ?, 'pcs', 1)",
                [$product_id, $bc]
            );
            $seen[$bc] = true;
        }
    };

    if ($action === 'add') {
        $name         = sanitize($_POST['name'] ?? '');
        $barcode      = trim($_POST['barcode'] ?? '') ?: generateBarcode();
        $quantity     = intval($_POST['quantity'] ?? 0);
        $min_quantity = max(0, intval($_POST['min_quantity'] ?? 5));
        $category_id  = intval($_POST['category_id'] ?? 0) ?: null;
        $supplier_id  = intval($_POST['supplier_id'] ?? 0) ?: null;

        if (!$name) {
            $_SESSION['message'] = 'Product name is required';
            $_SESSION['message_type'] = 'error';
        } elseif ($db->fetchOne("SELECT id FROM products WHERE barcode = ?", [$barcode])) {
            $_SESSION['message'] = 'Barcode already exists — choose a unique barcode';
            $_SESSION['message_type'] = 'error';
        } else {
            $db->execute(
                "INSERT INTO products (name, barcode, price_retail, price_wholesale, quantity, min_quantity, category_id, supplier_id) VALUES (?, ?, 0, 0, ?, ?, ?, ?)",
                [$name, $barcode, $quantity, $min_quantity, $category_id, $supplier_id]
            );
            $product_id = $db->lastInsertId();
            $syncTiers($product_id);
            $syncBarcodes($product_id, $barcode);
            logActivity($db, 'add_product', "Added product: {$name}", $product_id);
            $_SESSION['message'] = 'Product added successfully';
            $_SESSION['message_type'] = 'success';
        }

    } elseif ($action === 'edit') {
        $id           = intval($_POST['id'] ?? 0);
        $name         = sanitize($_POST['name'] ?? '');
        $quantity     = intval($_POST['quantity'] ?? 0);
        $min_quantity = max(0, intval($_POST['min_quantity'] ?? 5));
        $category_id  = intval($_POST['category_id'] ?? 0) ?: null;
        $supplier_id  = intval($_POST['supplier_id'] ?? 0) ?: null;

        if (!$id || !$name) {
            $_SESSION['message'] = 'Product ID and name are required';
            $_SESSION['message_type'] = 'error';
        } else {
            $existing = $db->fetchOne("SELECT barcode FROM products WHERE id = ?", [$id]);
            if (!$existing) {
                $_SESSION['message'] = 'Product not found';
                $_SESSION['message_type'] = 'error';
            } else {
                $db->execute(
                    "UPDATE products SET name = ?, quantity = ?, min_quantity = ?, category_id = ?, supplier_id = ? WHERE id = ?",
                    [$name, $quantity, $min_quantity, $category_id, $supplier_id, $id]
                );
                $syncTiers($id);
                $syncBarcodes($id, $existing['barcode']);
                logActivity($db, 'edit_product', "Edited product ID {$id}: {$name}", $id);
                $_SESSION['message'] = 'Product updated successfully';
                $_SESSION['message_type'] = 'success';
            }
        }

    } elseif ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        if ($id > 0) {
            $p = $db->fetchOne("SELECT name FROM products WHERE id = ?", [$id]);
            $db->execute("UPDATE products SET active = 0 WHERE id = ?", [$id]);
            logActivity($db, 'delete_product', "Soft-deleted product ID {$id}: " . ($p['name'] ?? '?'), $id);
            $_SESSION['message'] = 'Product removed successfully';
            $_SESSION['message_type'] = 'success';
        }
    }

    redirect(BASE_URL . '/pages/products.php');
}

// ── Data fetch ────────────────────────────────────────────────────────────────
$products = $db->fetchAll(
    "SELECT p.id, p.name, p.barcode, p.quantity, p.min_quantity,
            p.price_retail, p.price_wholesale,
            p.category_id, p.supplier_id,
            c.name AS category_name, s.name AS supplier_name
     FROM products p
     LEFT JOIN categories c ON p.category_id = c.id
     LEFT JOIN suppliers s  ON p.supplier_id  = s.id
     WHERE p.active = 1
     ORDER BY p.name ASC"
);

// Pricing tiers map: product_id → array of tiers
$tiers_map = [];
try {
    $all_tiers = $db->fetchAll(
        "SELECT ppt.* FROM product_price_tiers ppt
         JOIN products p ON p.id = ppt.product_id AND p.active = 1
         ORDER BY ppt.product_id, ppt.sort_order, ppt.id"
    );
    foreach ($all_tiers as $t) {
        $tiers_map[$t['product_id']][] = $t;
    }
} catch (Exception $e) { /* table may not exist yet */ }

// Barcodes map: product_id → [barcode, ...]
$barcodes_map = [];
try {
    $all_barcodes = $db->fetchAll(
        "SELECT pb.product_id, pb.barcode FROM product_barcodes pb
         JOIN products p ON p.id = pb.product_id AND p.active = 1"
    );
    foreach ($all_barcodes as $b) {
        $barcodes_map[$b['product_id']][] = $b['barcode'];
    }
} catch (Exception $e) { /* table may not exist yet */ }

$csrf = getCsrfToken();
?>
<!DOCTYPE html>
<html lang="fil">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo APP_NAME; ?> — <?php echo $page_title; ?></title>
    <script>(function(){var t=localStorage.getItem('pos_theme')||'light';document.documentElement.setAttribute('data-theme',t);})();</script>
    <link rel="stylesheet" href="<?php echo CSS_URL; ?>/main.css">
    <style>
        /* Filter bar */
        .filter-bar { display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end; margin-bottom:var(--space-5,20px); }
        .filter-bar .fi { display:flex; flex-direction:column; gap:4px; }
        .filter-bar .fi label { font-size:.8rem; font-weight:600; color:var(--c-text-soft,var(--color-gray)); }
        .filter-bar select, .filter-bar input[type="text"] {
            padding:8px 12px; border:1.5px solid var(--c-border,var(--color-border));
            border-radius:var(--radius-md,6px); font-size:.88rem;
            background:var(--c-surface,var(--color-card)); color:var(--c-text,var(--color-dark));
            font-family:var(--font-sans,inherit);
        }
        .filter-bar select:focus, .filter-bar input[type="text"]:focus { outline:none; border-color:var(--c-primary,var(--color-primary)); }

        /* Tiers pill list */
        .tier-pills { display:flex; flex-wrap:wrap; gap:4px; }
        .tier-pill {
            font-size:.75rem; padding:2px 8px;
            border-radius:999px;
            background:var(--c-accent,var(--color-accent));
            color:var(--c-text,var(--color-dark));
            white-space:nowrap;
        }
        .tier-pill--retail    { background:#e8f5e9; color:#2e7d32; }
        .tier-pill--wholesale { background:#e3f2fd; color:#1565c0; }
        .tier-pill--both      { background:var(--c-accent,var(--color-accent)); color:var(--c-text,var(--color-dark)); }
        .tier-mode-tag { font-style:normal; font-weight:700; margin-right:3px; font-size:.7rem; opacity:.8; }

        /* Barcode badges */
        .bc-primary { font-family:monospace; font-size:.8rem; background:var(--c-accent,var(--color-accent)); padding:2px 7px; border-radius:4px; }
        .bc-extra-count { font-size:.75rem; color:var(--c-text-soft,var(--color-gray)); margin-left:4px; }

        /* Modal — wider for tiers table */
        .modal {
            display:none; position:fixed; inset:0;
            background:rgba(0,0,0,.5);
            align-items:center; justify-content:center;
            z-index:1000; padding:20px;
        }
        .modal.active { display:flex; }
        .modal-content {
            background:var(--c-surface,#fff);
            border-radius:var(--radius-lg,10px);
            box-shadow:var(--shadow-xl,0 20px 60px rgba(0,0,0,.3));
            width:100%; max-width:680px;
            max-height:92vh; overflow-y:auto;
        }
        .modal-header {
            display:flex; justify-content:space-between; align-items:center;
            padding:20px 24px; border-bottom:1px solid var(--c-border,var(--color-border));
            position:sticky; top:0; background:var(--c-surface,#fff); z-index:1;
        }
        .modal-header h2 { margin:0; font-size:1.15rem; }
        .modal-close {
            background:none; border:none; font-size:1.4rem; cursor:pointer;
            color:var(--c-text-soft,var(--color-gray)); line-height:1; padding:4px;
        }
        .modal-close:hover { color:var(--c-text,var(--color-dark)); }
        .modal-body { padding:24px; }
        .modal-footer { padding:16px 24px; border-top:1px solid var(--c-border,var(--color-border)); display:flex; gap:10px; justify-content:flex-end; }

        .form-row { display:grid; gap:14px; margin-bottom:14px; }
        .form-row.cols-2 { grid-template-columns:1fr 1fr; }
        .form-row.cols-3 { grid-template-columns:1fr 1fr 1fr; }
        .form-group { display:flex; flex-direction:column; gap:5px; }
        .form-group label { font-size:.8rem; font-weight:600; color:var(--c-text-soft,var(--color-gray)); }

        /* Section header inside modal */
        .section-label {
            font-size:.75rem; font-weight:700; text-transform:uppercase;
            letter-spacing:.6px; color:var(--c-text-soft,var(--color-gray));
            border-bottom:1px solid var(--c-border,var(--color-border));
            padding-bottom:6px; margin:20px 0 12px;
        }

        /* Tiers editor table */
        .tiers-table { width:100%; border-collapse:collapse; font-size:.85rem; }
        .tiers-table th {
            text-align:left; font-size:.75rem; font-weight:700;
            text-transform:uppercase; letter-spacing:.5px;
            color:var(--c-text-soft,var(--color-gray));
            padding:6px 8px; border-bottom:1px solid var(--c-border,var(--color-border));
        }
        .tiers-table td { padding:5px 4px; vertical-align:middle; }
        .tiers-table td input {
            width:100%; padding:6px 8px; font-size:.85rem;
            border:1.5px solid var(--c-border,var(--color-border));
            border-radius:var(--radius-md,6px);
            background:var(--c-surface,#fff); color:var(--c-text,#111);
            font-family:inherit; box-sizing:border-box;
        }
        .tiers-table td input:focus { outline:none; border-color:var(--c-primary,var(--color-primary)); }
        .tiers-table .td-rm { width:36px; text-align:center; }
        .btn-rm-tier {
            background:none; border:none; cursor:pointer;
            font-size:1rem; color:var(--c-danger,#e53e3e); padding:4px; line-height:1;
        }
        .btn-rm-tier:hover { opacity:.7; }

        @media(max-width:600px) {
            .form-row.cols-2, .form-row.cols-3 { grid-template-columns:1fr; }
            .modal-content { max-width:100%; }
            .tiers-table .hide-mobile { display:none; }
        }
    </style>
</head>
<body class="dashboard-page">
    <?php include __DIR__ . '/../templates/navbar.php'; ?>

    <div class="dashboard-container">
        <div class="page-header">
            <h1><?php echo $page_title; ?></h1>
            <button type="button" class="btn btn-primary" onclick="openAddModal()">+ Add Product</button>
        </div>

        <?php displayMessage(); ?>

        <!-- Filter bar -->
        <div class="filter-bar">
            <div class="fi">
                <label for="srchName">Search</label>
                <input type="text" id="srchName" placeholder="Name or barcode…" oninput="applyFilters()">
            </div>
            <div class="fi">
                <label for="srchCat">Category</label>
                <select id="srchCat" onchange="applyFilters()">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $c): ?>
                        <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="fi">
                <label for="srchStock">Stock</label>
                <select id="srchStock" onchange="applyFilters()">
                    <option value="">All</option>
                    <option value="ok">OK</option>
                    <option value="low">Low</option>
                    <option value="out">Out</option>
                </select>
            </div>
            <div class="fi">
                <label for="srchMode">Price Mode</label>
                <select id="srchMode" onchange="applyFilters()">
                    <option value="">All Modes</option>
                    <option value="retail">Retail</option>
                    <option value="wholesale">Wholesale</option>
                </select>
            </div>
        </div>

        <!-- Products table -->
        <div class="card card-flat" style="overflow-x:auto;">
            <table class="data-table" id="productsTable">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Barcode(s)</th>
                        <th>Pricing Tiers</th>
                        <th>Stock</th>
                        <th>Category</th>
                        <th>Supplier</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($products)): ?>
                    <tr>
                        <td colspan="7" style="text-align:center;padding:48px;color:var(--c-text-soft,var(--color-gray));">
                            No products yet. <a href="javascript:void(0)" onclick="openAddModal()">Add the first one</a>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($products as $p):
                        $tiers    = $tiers_map[$p['id']] ?? [];
                        $barcodes = $barcodes_map[$p['id']] ?? [];
                        $qty = $p['quantity'];
                        $min = $p['min_quantity'] ?? 5;
                        $stock_flag = ($qty <= 0) ? 'out' : (($qty <= $min) ? 'low' : 'ok');
                        // search text
                        $all_bcs = implode(' ', $barcodes);
                        $tier_text = implode(' ', array_column($tiers, 'tier_name'));
                        $search_text = strtolower($p['name'] . ' ' . $all_bcs . ' ' . $tier_text);
                        // price modes present in this product's tiers
                        $tier_modes = array_unique(array_column($tiers, 'price_mode'));
                        $mode_str = implode(',', $tier_modes);
                    ?>
                    <tr class="table-row"
                        data-search="<?php echo htmlspecialchars($search_text); ?>"
                        data-cat="<?php echo $p['category_id'] ?? ''; ?>"
                        data-stock="<?php echo $stock_flag; ?>"
                        data-mode="<?php echo htmlspecialchars($mode_str); ?>">
                        <td><strong><?php echo htmlspecialchars($p['name']); ?></strong></td>
                        <td>
                            <span class="bc-primary"><?php echo htmlspecialchars($p['barcode']); ?></span>
                            <?php $extra_count = count($barcodes) - 1; if ($extra_count > 0): ?>
                                <span class="bc-extra-count">+<?php echo $extra_count; ?> more</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($tiers): ?>
                                <div class="tier-pills">
                                    <?php foreach ($tiers as $t):
                                        $mode_label = ['retail'=>'R','wholesale'=>'W','both'=>''][$t['price_mode'] ?? 'both'] ?? '';
                                    ?>
                                        <span class="tier-pill tier-pill--<?php echo $t['price_mode'] ?? 'both'; ?>">
                                            <?php if ($mode_label): ?><em class="tier-mode-tag"><?php echo $mode_label; ?></em><?php endif; ?>
                                            <?php echo htmlspecialchars($t['tier_name']); ?> <?php echo formatCurrency($t['price']); ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <span style="color:var(--c-text-soft,var(--color-gray));font-size:.8rem;">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($qty === null): ?>
                                <span class="badge badge-neutral">N/A</span>
                            <?php elseif ($qty <= 0): ?>
                                <strong style="color:var(--c-danger,#e53e3e);"><?php echo $qty; ?></strong>
                                <span class="badge badge-danger">OUT</span>
                            <?php elseif ($qty <= $min): ?>
                                <strong style="color:var(--c-warning,#d69e2e);"><?php echo $qty; ?></strong>
                                <span class="badge badge-warning">LOW</span>
                            <?php else: ?>
                                <strong><?php echo $qty; ?></strong>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($p['category_name'] ?? '—'); ?></td>
                        <td><?php echo htmlspecialchars($p['supplier_name'] ?? '—'); ?></td>
                        <td style="white-space:nowrap;">
                            <button class="btn btn-sm btn-secondary" onclick="editProduct(<?php echo $p['id']; ?>)">Edit</button>
                            <button class="btn btn-sm btn-danger" onclick="confirmDelete(<?php echo $p['id']; ?>, '<?php echo htmlspecialchars(addslashes($p['name'])); ?>')">Del</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ── Add / Edit Modal ────────────────────────────────────────────────── -->
    <div class="modal" id="productModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="modalTitle">Add Product</h2>
                <button class="modal-close" onclick="closeModals()">×</button>
            </div>
            <form method="POST" id="productForm">
                <?php csrfInput(); ?>
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="id"     id="formId">

                <div class="modal-body">
                    <!-- Name -->
                    <div class="form-group" style="margin-bottom:14px;">
                        <label for="fName">Product Name *</label>
                        <input type="text" id="fName" name="name" class="form-input" required placeholder="e.g. Lucky Me Pancit Canton">
                    </div>

                    <!-- Barcode -->
                    <div class="form-group" style="margin-bottom:14px;">
                        <label for="fBarcode">Primary Barcode</label>
                        <input type="text" id="fBarcode" name="barcode" class="form-input" placeholder="Auto-generated if empty">
                        <small style="color:var(--c-text-soft,var(--color-gray));">Used as the main identifier. Cannot be changed after creation.</small>
                    </div>

                    <!-- Category + Supplier -->
                    <div class="form-row cols-2">
                        <div class="form-group">
                            <label for="fCat">Category</label>
                            <select id="fCat" name="category_id" class="form-input">
                                <option value="">— Select —</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="fSupp">Supplier</label>
                            <select id="fSupp" name="supplier_id" class="form-input">
                                <option value="">— Select —</option>
                                <?php foreach ($suppliers as $s): ?>
                                    <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- Stock qty + min -->
                    <div class="form-row cols-2">
                        <div class="form-group">
                            <label for="fQty">Stock Qty</label>
                            <input type="number" id="fQty" name="quantity" class="form-input" min="0" step="1" value="0">
                        </div>
                        <div class="form-group">
                            <label for="fMin">Min. Level</label>
                            <input type="number" id="fMin" name="min_quantity" class="form-input" min="0" step="1" value="5">
                        </div>
                    </div>

                    <!-- Pricing tiers -->
                    <div class="section-label">Pricing Tiers</div>
                    <table class="tiers-table" id="tiersTable">
                        <thead>
                            <tr>
                                <th style="min-width:120px;">Tier Name</th>
                                <th style="min-width:90px;">Price (₱)</th>
                                <th class="hide-mobile" style="min-width:80px;">Unit</th>
                                <th class="hide-mobile" style="min-width:70px;">Multiplier</th>
                                <th style="min-width:100px;">Mode</th>
                                <th class="td-rm"></th>
                            </tr>
                        </thead>
                        <tbody id="tiersBody">
                            <!-- rows injected by JS -->
                        </tbody>
                    </table>
                    <button type="button" class="btn btn-sm btn-outline" style="margin-top:8px;" onclick="addTierRow()">+ Add Tier</button>

                    <!-- Extra barcodes -->
                    <div class="section-label">Extra Barcodes</div>
                    <div class="form-group">
                        <label for="fExtraBarcodes">Additional barcodes (one per line)</label>
                        <textarea id="fExtraBarcodes" name="extra_barcodes" class="form-input" rows="3"
                                  placeholder="e.g. barcode for pack, wholesale unit, etc."
                                  style="resize:vertical;font-family:monospace;font-size:.85rem;"></textarea>
                        <small style="color:var(--c-text-soft,var(--color-gray));">Primary barcode is always included automatically.</small>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModals()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Product</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ── Delete Confirm Modal ───────────────────────────────────────────── -->
    <div class="modal" id="deleteModal">
        <div class="modal-content" style="max-width:400px;">
            <div class="modal-header">
                <h2>Remove Product</h2>
                <button class="modal-close" onclick="closeModals()">×</button>
            </div>
            <div class="modal-body">
                <p>Remove <strong id="delName"></strong>?</p>
                <p style="color:var(--c-text-soft,var(--color-gray));font-size:.85rem;">The product will be hidden from all views (soft delete).</p>
            </div>
            <form method="POST" id="deleteForm">
                <?php csrfInput(); ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" id="delId">
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeModals()">Cancel</button>
                    <button type="submit" class="btn btn-danger">Remove</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Embedded product data for JS -->
    <script>
    const PRODUCTS  = <?php echo json_encode(array_column($products, null, 'id'), JSON_HEX_TAG | JSON_HEX_AMP); ?>;
    const TIERS_MAP = <?php echo json_encode($tiers_map, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
    const BC_MAP    = <?php echo json_encode($barcodes_map, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
    </script>

    <script>
    // ── Tier rows ─────────────────────────────────────────────────────────────
    function addTierRow(name='', price='', unit='pcs', mult='1', mode='both') {
        const tbody = document.getElementById('tiersBody');
        const tr = document.createElement('tr');
        const modeOpts = ['retail','wholesale','both'].map(v =>
            `<option value="${v}"${v===mode?' selected':''}>${v.charAt(0).toUpperCase()+v.slice(1)}</option>`
        ).join('');
        tr.innerHTML = `
            <td><input type="text" name="tier_name[]" value="${esc(name)}" placeholder="e.g. Retail" required></td>
            <td><input type="number" name="tier_price[]" value="${esc(price)}" min="0.01" step="0.01" placeholder="0.00" required></td>
            <td class="hide-mobile"><input type="text" name="tier_unit_label[]" value="${esc(unit)}" placeholder="pcs"></td>
            <td class="hide-mobile"><input type="number" name="tier_qty_mult[]" value="${esc(mult)}" min="0.0001" step="0.0001" placeholder="1"></td>
            <td><select name="tier_mode[]" style="width:100%;padding:6px 4px;border:1.5px solid var(--c-border,#ccc);border-radius:var(--radius-md,6px);font-size:.83rem;background:var(--c-surface,#fff);color:var(--c-text,#111);">${modeOpts}</select></td>
            <td class="td-rm"><button type="button" class="btn-rm-tier" onclick="removeTierRow(this)">×</button></td>
        `;
        tbody.appendChild(tr);
    }

    function removeTierRow(btn) {
        const tbody = document.getElementById('tiersBody');
        if (tbody.rows.length <= 1) { alert('At least one pricing tier is required.'); return; }
        btn.closest('tr').remove();
    }

    function esc(v) {
        return String(v).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

    // ── Open Add Modal ────────────────────────────────────────────────────────
    function openAddModal() {
        document.getElementById('modalTitle').textContent  = 'Add Product';
        document.getElementById('formAction').value        = 'add';
        document.getElementById('formId').value            = '';
        document.getElementById('fName').value             = '';
        document.getElementById('fBarcode').value          = '';
        document.getElementById('fBarcode').readOnly       = false;
        document.getElementById('fCat').value              = '';
        document.getElementById('fSupp').value             = '';
        document.getElementById('fQty').value              = '0';
        document.getElementById('fMin').value              = '5';
        document.getElementById('fExtraBarcodes').value    = '';
        document.getElementById('tiersBody').innerHTML     = '';
        // Seed with standard tiers
        addTierRow('Retail',    '', 'pcs',  '1', 'retail');
        addTierRow('Wholesale', '', 'pcs',  '1', 'wholesale');
        document.getElementById('productModal').classList.add('active');
        document.getElementById('fName').focus();
    }

    // ── Open Edit Modal ───────────────────────────────────────────────────────
    function editProduct(id) {
        const p = PRODUCTS[id];
        if (!p) { alert('Product data not found.'); return; }

        document.getElementById('modalTitle').textContent  = 'Edit Product';
        document.getElementById('formAction').value        = 'edit';
        document.getElementById('formId').value            = p.id;
        document.getElementById('fName').value             = p.name;
        document.getElementById('fBarcode').value          = p.barcode;
        document.getElementById('fBarcode').readOnly       = true;
        document.getElementById('fCat').value              = p.category_id  || '';
        document.getElementById('fSupp').value             = p.supplier_id  || '';
        document.getElementById('fQty').value              = p.quantity  ?? 0;
        document.getElementById('fMin').value              = p.min_quantity ?? 5;

        // Load tiers
        document.getElementById('tiersBody').innerHTML = '';
        const tiers = TIERS_MAP[id] || [];
        if (tiers.length) {
            tiers.forEach(t => addTierRow(t.tier_name, t.price, t.unit_label, t.qty_multiplier, t.price_mode || 'both'));
        } else {
            addTierRow('Retail',    p.price_retail    || '', 'pcs', '1', 'retail');
            addTierRow('Wholesale', p.price_wholesale || '', 'pcs', '1', 'wholesale');
        }

        // Load extra barcodes (exclude primary)
        const barcodes = (BC_MAP[id] || []).filter(b => b !== p.barcode);
        document.getElementById('fExtraBarcodes').value = barcodes.join('\n');

        document.getElementById('productModal').classList.add('active');
        document.getElementById('fName').focus();
    }

    // ── Delete confirm ────────────────────────────────────────────────────────
    function confirmDelete(id, name) {
        document.getElementById('delName').textContent = name;
        document.getElementById('delId').value         = id;
        document.getElementById('deleteModal').classList.add('active');
    }

    function closeModals() {
        document.getElementById('productModal').classList.remove('active');
        document.getElementById('deleteModal').classList.remove('active');
    }

    // Close on backdrop click
    ['productModal','deleteModal'].forEach(id => {
        document.getElementById(id).addEventListener('click', function(e) {
            if (e.target === this) closeModals();
        });
    });

    // ── Table filter ──────────────────────────────────────────────────────────
    function applyFilters() {
        const q     = document.getElementById('srchName').value.toLowerCase();
        const cat   = document.getElementById('srchCat').value;
        const stock = document.getElementById('srchStock').value;
        const mode  = document.getElementById('srchMode').value;

        document.querySelectorAll('#productsTable .table-row').forEach(row => {
            const matchQ     = !q     || row.dataset.search.includes(q);
            const matchCat   = !cat   || row.dataset.cat   === cat;
            const matchStock = !stock || row.dataset.stock  === stock;
            const modeData   = (row.dataset.mode || '').split(',');
            const matchMode  = !mode  || modeData.some(m => m === mode || m === 'both');
            row.style.display = (matchQ && matchCat && matchStock && matchMode) ? '' : 'none';
        });
    }
    </script>

    <script src="<?php echo JS_URL; ?>/main.js"></script>
</body>
</html>
