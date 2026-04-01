<?php
/**
 * J&J Grocery POS - Inventory Management
 * For inventory checkers and admins to manage stock levels
 */

session_start();
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

if (!isLoggedIn()) redirect(BASE_URL . '/index.php');
if (!hasAccess('inventory')) {
    redirect(BASE_URL . '/pages/dashboard.php');
}

checkSessionTimeout();

$page_title = 'Inventory';

// Dropdown data
$categories = $db->fetchAll("SELECT id, name FROM categories ORDER BY name");
$suppliers   = $db->fetchAll("SELECT id, name FROM suppliers ORDER BY name");

// Products with extra barcodes
$products = $db->fetchAll(
    "SELECT p.id, p.name, p.barcode, p.quantity, p.min_quantity,
            p.price_retail, p.price_wholesale,
            c.name AS category_name, c.id AS category_id,
            s.name AS supplier_name, s.id AS supplier_id
     FROM products p
     LEFT JOIN categories c ON p.category_id = c.id
     LEFT JOIN suppliers s  ON p.supplier_id  = s.id
     WHERE p.active = 1
     ORDER BY c.name, p.name"
);

// Attach extra barcodes
$extra_barcodes_map = [];
try {
    $eb = $db->fetchAll("SELECT product_id, barcode FROM product_barcodes");
    foreach ($eb as $row) $extra_barcodes_map[$row['product_id']][] = $row['barcode'];
} catch (Exception $e) { /* table may not exist */ }

// Pricing tiers map
$tiers_map = [];
try {
    $all_tiers = $db->fetchAll(
        "SELECT product_id, tier_name, price, price_mode FROM product_price_tiers ORDER BY product_id, sort_order, id"
    );
    foreach ($all_tiers as $t) $tiers_map[$t['product_id']][] = $t;
} catch (Exception $e) { /* table may not exist */ }

// Last sold date per product
$last_sold_map = [];
try {
    $ls = $db->fetchAll(
        "SELECT si.product_id, MAX(s.created_at) AS last_sold
         FROM sale_items si
         JOIN sales s ON s.id = si.sale_id
         WHERE s.voided = 0
         GROUP BY si.product_id"
    );
    foreach ($ls as $row) $last_sold_map[$row['product_id']] = $row['last_sold'];
} catch (Exception $e) { /* safe to skip */ }

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
        .filter-bar { display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end; margin-bottom: var(--space-5); }
        .filter-bar .filter-item { display: flex; flex-direction: column; gap: 4px; }
        .filter-bar .filter-item label { font-size:.8rem; font-weight:600; color:var(--c-text-soft); }
        .filter-bar select, .filter-bar input { padding:8px 12px; border:1.5px solid var(--c-border); border-radius:var(--radius-md); font-size:.88rem; background:var(--c-surface); color:var(--c-text); font-family:var(--font-sans); }
        .filter-bar select:focus, .filter-bar input:focus { outline:none; border-color:var(--c-primary); }
        .filter-bar .filter-search { flex:1; min-width:200px; }
        .stock-badge { display:inline-flex; align-items:center; padding:2px 8px; border-radius:var(--radius-pill); font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.3px; }
        .stock-ok       { background:var(--c-success-light); color:var(--c-success); }
        .stock-low      { background:var(--c-warning-light); color:var(--c-warning); }
        .stock-critical { background:var(--c-danger-light);  color:var(--c-danger);  }
        .stock-out      { background:var(--c-danger-light);  color:var(--c-danger);  }
        .stock-na       { background:var(--c-bg);            color:var(--c-text-soft); }
        .barcodes-list { font-size:.75rem; color:var(--c-text-soft); margin-top:3px; }
    </style>
</head>
<body class="dashboard-page">
    <?php include __DIR__ . '/../templates/navbar.php'; ?>

    <div class="dashboard-container">
        <div class="page-header">
            <div>
                <h1><?php echo $page_title; ?></h1>
                <p class="text-muted"><?php echo count($products); ?> products</p>
            </div>
            <button class="btn btn-secondary" onclick="exportInventory()">Export CSV</button>
        </div>

        <?php displayMessage(); ?>

        <!-- Filters -->
        <div class="filter-bar">
            <div class="filter-item filter-search">
                <label>Search</label>
                <input type="text" id="searchInput" placeholder="Product name or barcode..." oninput="applyFilters()">
            </div>
            <div class="filter-item">
                <label>Category</label>
                <select id="categoryFilter" onchange="applyFilters()">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-item">
                <label>Supplier</label>
                <select id="supplierFilter" onchange="applyFilters()">
                    <option value="">All Suppliers</option>
                    <?php foreach ($suppliers as $s): ?>
                        <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-item">
                <label>Stock Status</label>
                <select id="stockFilter" onchange="applyFilters()">
                    <option value="">All</option>
                    <option value="ok">OK</option>
                    <option value="low">Low</option>
                    <option value="critical">Critical / Out</option>
                    <option value="na">Untracked</option>
                </select>
            </div>
        </div>

        <!-- Table -->
        <div class="card card-flat" style="overflow-x:auto">
            <table class="data-table" id="inventoryTable">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Barcodes</th>
                        <th>Category</th>
                        <th>Supplier</th>
                        <th>Stock</th>
                        <th>Min</th>
                        <th>Retail / Wholesale</th>
                        <th>Last Sold</th>
                        <th style="width:100px">Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($products as $p):
                    $qty  = $p['quantity'];
                    $min  = max(1, (int)($p['min_quantity'] ?? 5));
                    if ($qty === null)          { $status = 'na';       $badge = 'stock-na';       $badge_text = 'N/A'; }
                    elseif ($qty <= 0)          { $status = 'critical'; $badge = 'stock-out';      $badge_text = 'OUT'; }
                    elseif ($qty <= $min)       { $status = 'critical'; $badge = 'stock-critical'; $badge_text = 'CRITICAL'; }
                    elseif ($qty <= $min * 1.5) { $status = 'low';      $badge = 'stock-low';      $badge_text = 'LOW'; }
                    else                        { $status = 'ok';       $badge = 'stock-ok';       $badge_text = 'OK'; }

                    $all_barcodes = [$p['barcode']];
                    foreach (($extra_barcodes_map[$p['id']] ?? []) as $eb) {
                        if ($eb !== $p['barcode']) $all_barcodes[] = $eb;
                    }

                    $tiers     = $tiers_map[$p['id']] ?? [];
                    $last_sold = $last_sold_map[$p['id']] ?? null;

                    // Reorder suggestion: suggest enough to reach 2× min level
                    $reorder_qty = ($qty !== null && $qty < $min) ? max(0, ($min * 2) - $qty) : 0;
                ?>
                    <tr data-name="<?php echo strtolower(htmlspecialchars($p['name'])); ?>"
                        data-barcodes="<?php echo htmlspecialchars(implode(' ', $all_barcodes), ENT_QUOTES, 'UTF-8'); ?>"
                        data-category="<?php echo $p['category_id']; ?>"
                        data-supplier="<?php echo $p['supplier_id']; ?>"
                        data-status="<?php echo $status; ?>"
                        data-retail="<?php echo number_format($p['price_retail'] ?? 0, 2, '.', ''); ?>"
                        data-wholesale="<?php echo number_format($p['price_wholesale'] ?? 0, 2, '.', ''); ?>">
                        <td><strong><?php echo htmlspecialchars($p['name']); ?></strong></td>
                        <td>
                            <code><?php echo htmlspecialchars($p['barcode']); ?></code>
                            <?php if (count($all_barcodes) > 1): ?>
                                <div class="barcodes-list"><?php echo htmlspecialchars(implode(', ', array_slice($all_barcodes, 1))); ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($p['category_name'] ?? '—'); ?></td>
                        <td><?php echo htmlspecialchars($p['supplier_name'] ?? '—'); ?></td>
                        <td>
                            <span class="stock-badge <?php echo $badge; ?>"><?php echo $badge_text; ?></span>
                            <?php if ($qty !== null): ?> <strong><?php echo $qty; ?></strong><?php endif; ?>
                            <?php if ($reorder_qty > 0): ?>
                                <div style="font-size:.7rem;color:var(--c-warning,#d69e2e);margin-top:2px;">Order ~<?php echo $reorder_qty; ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="text-muted"><?php echo $min; ?></td>
                        <td class="text-sm">
                            <?php
                            $price_parts = [];
                            if ($p['price_retail'] > 0)    $price_parts[] = '<span style="color:var(--c-text)">₱' . number_format($p['price_retail'],2) . '</span>';
                            if ($p['price_wholesale'] > 0) $price_parts[] = '<span style="color:var(--c-info,#1565c0)">₱' . number_format($p['price_wholesale'],2) . ' W</span>';
                            echo $price_parts ? implode(' / ', $price_parts) : '<span class="text-muted">—</span>';
                            ?>
                            <?php if ($tiers): ?>
                                <div style="display:flex;flex-wrap:wrap;gap:3px;margin-top:3px;">
                                <?php foreach ($tiers as $t):
                                    $tc = ['retail'=>'var(--c-success,#2e7d32)','wholesale'=>'var(--c-info,#1565c0)','both'=>'var(--c-text-soft)'][$t['price_mode'] ?? 'both'] ?? 'var(--c-text-soft)';
                                ?>
                                    <span style="font-size:.7rem;padding:1px 6px;border-radius:999px;background:var(--c-bg,#f5f5f5);color:<?php echo $tc; ?>;white-space:nowrap;">
                                        <?php echo htmlspecialchars($t['tier_name']); ?> ₱<?php echo number_format($t['price'],2); ?>
                                    </span>
                                <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td class="text-sm text-muted">
                            <?php echo $last_sold ? formatDate($last_sold) : '<span style="opacity:.5">—</span>'; ?>
                        </td>
                        <td>
                            <?php if ($qty !== null): ?>
                                <button class="btn btn-xs btn-secondary"
                                    onclick="openStockModal(<?php echo $p['id']; ?>,'<?php echo htmlspecialchars(addslashes($p['name'])); ?>',<?php echo (int)$qty; ?>)">
                                    Update
                                </button>
                            <?php else: ?>
                                <span class="text-muted text-xs">Untracked</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <div id="emptyMsg" style="display:none;padding:40px;text-align:center;color:var(--c-text-soft)">No products match the current filters.</div>
        </div>
    </div>

    <!-- Stock Update Modal -->
    <div class="modal" id="stockModal">
        <div class="modal-content" style="max-width:380px">
            <div class="modal-header">
                <h2>Update Stock — <span id="stockProductName"></span></h2>
                <button class="modal-close" onclick="closeStockModal()">×</button>
            </div>
            <form class="modal-form" style="padding:var(--space-5) var(--space-6)" onsubmit="saveStock(event)">
                <input type="hidden" name="action" value="update_stock">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                <input type="hidden" id="stockProductId" name="product_id">
                <div class="form-group">
                    <label>New Stock Level</label>
                    <input type="number" id="stockQuantity" name="quantity" class="form-input" min="0" required autofocus>
                </div>
                <div class="modal-actions">
                    <button type="submit" id="stockSubmitBtn" class="btn btn-primary">Update Stock</button>
                    <button type="button" class="btn btn-ghost" onclick="closeStockModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function applyFilters() {
            const search   = document.getElementById('searchInput').value.toLowerCase();
            const category = document.getElementById('categoryFilter').value;
            const supplier = document.getElementById('supplierFilter').value;
            const stock    = document.getElementById('stockFilter').value;
            const rows     = document.querySelectorAll('#inventoryTable tbody tr');
            let visible    = 0;

            rows.forEach(row => {
                const nameMatch     = !search   || row.dataset.name.includes(search) || row.dataset.barcodes.toLowerCase().includes(search);
                const categoryMatch = !category || row.dataset.category === category;
                const supplierMatch = !supplier || row.dataset.supplier === supplier;
                const stockMatch    = !stock    || row.dataset.status === stock;
                const show = nameMatch && categoryMatch && supplierMatch && stockMatch;
                row.style.display = show ? '' : 'none';
                if (show) visible++;
            });

            document.getElementById('emptyMsg').style.display = visible === 0 ? '' : 'none';
        }

        function openStockModal(id, name, qty) {
            document.getElementById('stockProductId').value = id;
            document.getElementById('stockProductName').textContent = name;
            document.getElementById('stockQuantity').value = qty;
            document.getElementById('stockModal').classList.add('active');
            setTimeout(() => document.getElementById('stockQuantity').focus(), 50);
        }

        function closeStockModal() { document.getElementById('stockModal').classList.remove('active'); }

        function saveStock(event) {
            event.preventDefault();
            const btn = document.getElementById('stockSubmitBtn');
            btn.disabled = true;
            btn.textContent = 'Saving…';

            const fd = new FormData(event.target);

            fetch('<?php echo API_URL; ?>/inventory.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(d => {
                    btn.disabled = false; btn.textContent = 'Update Stock';
                    if (d.success) { closeStockModal(); location.reload(); }
                    else alert(d.error || 'Failed to update stock');
                })
                .catch(() => { btn.disabled = false; btn.textContent = 'Update Stock'; alert('Network error'); });
        }

        function exportInventory() {
            const rows = document.querySelectorAll('#inventoryTable tbody tr');
            let csv = 'Product,Primary Barcode,Category,Supplier,Stock,Min Level,Retail Price,Wholesale Price,Last Sold\n';
            rows.forEach(row => {
                if (row.style.display === 'none') return;
                const cells    = row.querySelectorAll('td');
                const name     = '"' + cells[0].textContent.trim().replace(/"/g,'""') + '"';
                const barcode  = '"' + cells[1].textContent.trim().split('\n')[0].replace(/"/g,'""') + '"';
                const category = '"' + cells[2].textContent.trim() + '"';
                const supplier = '"' + cells[3].textContent.trim() + '"';
                const stock    = cells[4].textContent.trim().replace(/\s+/g,' ').replace(/Order ~\d+/,'').trim();
                const min      = cells[5].textContent.trim();
                const retail     = row.dataset.retail && row.dataset.retail !== '0.00' ? row.dataset.retail : '';
                const wholesale  = row.dataset.wholesale && row.dataset.wholesale !== '0.00' ? row.dataset.wholesale : '';
                const lastSold   = '"' + cells[7].textContent.trim() + '"';
                csv += [name, barcode, category, supplier, stock, min, retail, wholesale, lastSold].join(',') + '\n';
            });
            const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
            const url  = URL.createObjectURL(blob);
            const a    = document.createElement('a');
            a.href = url; a.download = 'inventory_' + new Date().toISOString().slice(0,10) + '.csv';
            a.click(); URL.revokeObjectURL(url);
        }

        document.getElementById('stockModal').addEventListener('click', e => {
            if (e.target === document.getElementById('stockModal')) closeStockModal();
        });
    </script>
</body>
</html>
