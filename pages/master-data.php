<?php
/**
 * J&J Grocery POS — Master Data v4
 * Tabs: Categories | Suppliers | Business Settings
 * Requires master_data permission (v4) or admin role (legacy fallback).
 */

session_start();
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

if (!isLoggedIn()) redirect(BASE_URL . '/index.php');
checkSessionTimeout();
if (!hasAccess('master_data')) redirect(BASE_URL . '/pages/dashboard.php');

$db   = new Database();
$user = getCurrentUser();

// ── Data ─────────────────────────────────────────────────────
$categories = $db->fetchAll(
    "SELECT c.id, c.name, COUNT(p.id) AS product_count
     FROM categories c
     LEFT JOIN products p ON p.category_id = c.id
     GROUP BY c.id, c.name
     ORDER BY c.name"
);

$suppliers = $db->fetchAll(
    "SELECT s.id, s.name, s.contact, s.email, COUNT(p.id) AS product_count
     FROM suppliers s
     LEFT JOIN products p ON p.supplier_id = s.id
     GROUP BY s.id, s.name, s.contact, s.email
     ORDER BY s.name"
);

$biz = getBusinessSettings($db);

$active_tab = $_GET['tab'] ?? 'categories';
$csrf = getCsrfToken();
?>
<!DOCTYPE html>
<html lang="fil">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo APP_NAME; ?> — Master Data</title>
    <script>(function(){var t=localStorage.getItem('pos_theme')||'light';document.documentElement.setAttribute('data-theme',t);})();</script>
    <link rel="stylesheet" href="<?php echo CSS_URL; ?>/main.css">
    <link rel="stylesheet" href="<?php echo CSS_URL; ?>/theme.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
    .tab-nav { display:flex;gap:4px;border-bottom:2px solid var(--c-border);margin-bottom:var(--space-5);flex-wrap:wrap; }
    .tab-nav .tab-btn { padding:var(--space-3) var(--space-4);background:none;border:none;border-bottom:3px solid transparent;cursor:pointer;font-size:.85rem;font-weight:600;color:var(--c-text-soft);font-family:var(--font-sans);transition:var(--transition);margin-bottom:-2px; }
    .tab-nav .tab-btn:hover { color:var(--c-text);background:rgba(211,47,47,.06);border-radius:var(--radius-md) var(--radius-md) 0 0; }
    .tab-nav .tab-btn.active { color:var(--c-primary);border-bottom-color:var(--c-primary); }
    .tab-pane { display:none; }
    .tab-pane.active { display:block; }

    /* Card grid for categories/suppliers */
    .master-grid { display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:var(--space-4); }
    .master-card { background:var(--c-surface);border:1.5px solid var(--c-border);border-radius:var(--radius-lg);padding:var(--space-4) var(--space-5);transition:var(--transition);position:relative; }
    .master-card:hover { border-color:var(--c-primary);box-shadow:0 2px 8px rgba(0,0,0,.06); }
    .master-card-name { font-weight:700;font-size:1rem;margin-bottom:4px;color:var(--c-text); }
    .master-card-meta { font-size:.82rem;color:var(--c-text-soft);margin-bottom:var(--space-3); }
    .master-card-actions { display:flex;gap:var(--space-2);margin-top:var(--space-3);padding-top:var(--space-3);border-top:1px solid var(--c-border); }
    .product-count-badge { display:inline-flex;align-items:center;gap:4px;font-size:.75rem;font-weight:600;padding:2px 8px;border-radius:var(--radius-pill);background:var(--c-primary-fade);color:var(--c-primary); }

    /* Settings form */
    .settings-grid { display:grid;grid-template-columns:1fr 1fr;gap:var(--space-4); }
    .settings-section { margin-bottom:var(--space-5); }
    .settings-section h4 { margin-bottom:var(--space-3);font-size:.9rem;color:var(--c-text-soft);text-transform:uppercase;letter-spacing:.04em; }
    .toggle-group { display:flex;align-items:center;gap:var(--space-3);padding:var(--space-3) 0; }
    .toggle-group label { font-weight:600;font-size:.88rem; }
    .toggle-switch { position:relative;width:44px;height:24px;display:inline-block; }
    .toggle-switch input { opacity:0;width:0;height:0; }
    .toggle-slider { position:absolute;top:0;left:0;right:0;bottom:0;background:var(--c-border);border-radius:24px;cursor:pointer;transition:.2s; }
    .toggle-slider::before { content:'';position:absolute;width:18px;height:18px;left:3px;bottom:3px;background:#fff;border-radius:50%;transition:.2s; }
    .toggle-switch input:checked + .toggle-slider { background:var(--c-primary); }
    .toggle-switch input:checked + .toggle-slider::before { transform:translateX(20px); }

    .search-bar { position:relative;margin-bottom:var(--space-4); }
    .search-bar input { width:100%;padding:var(--space-3) var(--space-4) var(--space-3) 36px;border:1.5px solid var(--c-border);border-radius:var(--radius-md);background:var(--c-surface);color:var(--c-text);font-size:.88rem;font-family:var(--font-sans); }
    .search-bar input:focus { border-color:var(--c-primary);outline:none; }
    .search-bar::before { content:'🔍';position:absolute;left:10px;top:50%;transform:translateY(-50%);font-size:.85rem; }

    .empty-state { text-align:center;padding:var(--space-8) 0;color:var(--c-text-soft); }
    .empty-state .icon { font-size:2.5rem;margin-bottom:var(--space-3); }

    @media(max-width:768px) {
        .settings-grid { grid-template-columns:1fr; }
        .master-grid { grid-template-columns:1fr; }
    }
    </style>
</head>
<body class="dashboard-page">
    <?php include __DIR__ . '/../templates/navbar.php'; ?>

    <div class="dashboard-container">

        <div class="page-header">
            <div>
                <h1>Master Data</h1>
                <p class="text-muted">Categories, suppliers, and business settings</p>
            </div>
        </div>

        <?php displayMessage(); ?>

        <!-- Tab Navigation -->
        <div class="tab-nav">
            <button class="tab-btn <?php echo $active_tab==='categories'?'active':''; ?>" onclick="switchTab('categories',this)">
                Categories <span class="badge badge-primary" style="margin-left:4px;"><?php echo count($categories); ?></span>
            </button>
            <button class="tab-btn <?php echo $active_tab==='suppliers'?'active':''; ?>" onclick="switchTab('suppliers',this)">
                Suppliers <span class="badge badge-primary" style="margin-left:4px;"><?php echo count($suppliers); ?></span>
            </button>
            <?php if (hasAccess('settings') || hasAccess('master_data')): ?>
            <button class="tab-btn <?php echo $active_tab==='settings'?'active':''; ?>" onclick="switchTab('settings',this)">
                Business Settings
            </button>
            <?php endif; ?>
        </div>

        <!-- ════════════════════════════════════════════════════ -->
        <!-- TAB: CATEGORIES                                     -->
        <!-- ════════════════════════════════════════════════════ -->
        <div id="tab-categories" class="tab-pane <?php echo $active_tab==='categories'?'active':''; ?>">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:var(--space-3);margin-bottom:var(--space-4);flex-wrap:wrap;">
                <div class="search-bar" style="flex:1;max-width:360px;">
                    <input type="text" id="catSearch" placeholder="Search categories..." oninput="filterCards('catSearch','catGrid')">
                </div>
                <button class="btn btn-primary" onclick="openCategoryModal('new')">+ Add Category</button>
            </div>

            <?php if (empty($categories)): ?>
            <div class="empty-state">
                <div class="icon">📂</div>
                <p>No categories yet. Add your first category to get started.</p>
            </div>
            <?php else: ?>
            <div class="master-grid" id="catGrid">
                <?php foreach ($categories as $cat): ?>
                <div class="master-card" data-name="<?php echo htmlspecialchars(strtolower($cat['name'])); ?>">
                    <div class="master-card-name"><?php echo htmlspecialchars($cat['name']); ?></div>
                    <div class="master-card-meta">
                        <span class="product-count-badge"><?php echo (int)$cat['product_count']; ?> product<?php echo $cat['product_count'] != 1 ? 's' : ''; ?></span>
                    </div>
                    <div class="master-card-actions">
                        <button class="btn btn-xs btn-secondary" onclick="openCategoryModal('edit',<?php echo $cat['id']; ?>,'<?php echo htmlspecialchars(addslashes($cat['name']), ENT_QUOTES); ?>')">Edit</button>
                        <button class="btn btn-xs btn-danger" onclick="deleteItem('category',<?php echo $cat['id']; ?>,'<?php echo htmlspecialchars(addslashes($cat['name']), ENT_QUOTES); ?>',<?php echo (int)$cat['product_count']; ?>)">Delete</button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- ════════════════════════════════════════════════════ -->
        <!-- TAB: SUPPLIERS                                      -->
        <!-- ════════════════════════════════════════════════════ -->
        <div id="tab-suppliers" class="tab-pane <?php echo $active_tab==='suppliers'?'active':''; ?>">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:var(--space-3);margin-bottom:var(--space-4);flex-wrap:wrap;">
                <div class="search-bar" style="flex:1;max-width:360px;">
                    <input type="text" id="suppSearch" placeholder="Search suppliers..." oninput="filterCards('suppSearch','suppGrid')">
                </div>
                <button class="btn btn-primary" onclick="openSupplierModal('new')">+ Add Supplier</button>
            </div>

            <?php if (empty($suppliers)): ?>
            <div class="empty-state">
                <div class="icon">🏭</div>
                <p>No suppliers yet. Add your first supplier to get started.</p>
            </div>
            <?php else: ?>
            <div class="master-grid" id="suppGrid">
                <?php foreach ($suppliers as $supp): ?>
                <div class="master-card" data-name="<?php echo htmlspecialchars(strtolower($supp['name'] . ' ' . ($supp['contact'] ?? '') . ' ' . ($supp['email'] ?? ''))); ?>">
                    <div class="master-card-name"><?php echo htmlspecialchars($supp['name']); ?></div>
                    <div class="master-card-meta">
                        <?php if ($supp['contact']): ?>
                            <div style="margin-bottom:2px;"><?php echo htmlspecialchars($supp['contact']); ?></div>
                        <?php endif; ?>
                        <?php if ($supp['email']): ?>
                            <div style="margin-bottom:2px;"><?php echo htmlspecialchars($supp['email']); ?></div>
                        <?php endif; ?>
                        <span class="product-count-badge" style="margin-top:4px;"><?php echo (int)$supp['product_count']; ?> product<?php echo $supp['product_count'] != 1 ? 's' : ''; ?></span>
                    </div>
                    <div class="master-card-actions">
                        <button class="btn btn-xs btn-secondary" onclick="openSupplierModal('edit',<?php echo $supp['id']; ?>,'<?php echo htmlspecialchars(addslashes($supp['name']), ENT_QUOTES); ?>','<?php echo htmlspecialchars(addslashes($supp['contact'] ?? ''), ENT_QUOTES); ?>','<?php echo htmlspecialchars(addslashes($supp['email'] ?? ''), ENT_QUOTES); ?>')">Edit</button>
                        <button class="btn btn-xs btn-danger" onclick="deleteItem('supplier',<?php echo $supp['id']; ?>,'<?php echo htmlspecialchars(addslashes($supp['name']), ENT_QUOTES); ?>',<?php echo (int)$supp['product_count']; ?>)">Delete</button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- ════════════════════════════════════════════════════ -->
        <!-- TAB: BUSINESS SETTINGS                              -->
        <!-- ════════════════════════════════════════════════════ -->
        <?php if (hasAccess('settings') || hasAccess('master_data')): ?>
        <div id="tab-settings" class="tab-pane <?php echo $active_tab==='settings'?'active':''; ?>">
            <div class="card card-flat">
                <h3 style="margin-bottom:var(--space-2);">Business Settings</h3>
                <p class="text-muted" style="font-size:.85rem;margin-bottom:var(--space-5);">
                    Configure your store information, VAT settings, and receipt options. Changes are saved to the database and take effect immediately.
                </p>

                <?php if (!$biz): ?>
                <div class="alert alert-danger">Business settings table not found. Run <code>migration_v4.sql</code> first.</div>
                <?php else: ?>
                <form id="settingsForm" onsubmit="saveSettings(event)" enctype="multipart/form-data">
                    <div class="settings-section">
                        <h4>Store Information</h4>
                        <div class="settings-grid">
                            <div class="form-group">
                                <label class="form-label">Business Name *</label>
                                <input type="text" id="bizName" class="form-input" value="<?php echo htmlspecialchars($biz['business_name'] ?? ''); ?>" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">TIN (Tax ID Number)</label>
                                <input type="text" id="bizTin" class="form-input" value="<?php echo htmlspecialchars($biz['tin'] ?? ''); ?>" placeholder="000-000-000-000">
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Business Address</label>
                            <input type="text" id="bizAddress" class="form-input" value="<?php echo htmlspecialchars($biz['business_address'] ?? ''); ?>" placeholder="Street, City, Province">
                        </div>
                    </div>

                    <div class="settings-section">
                        <h4>VAT Configuration</h4>
                        <div class="settings-grid">
                            <div>
                                <div class="toggle-group">
                                    <label class="toggle-switch">
                                        <input type="checkbox" id="bizVatReg" <?php echo ($biz['vat_registered'] ?? 1) ? 'checked' : ''; ?>>
                                        <span class="toggle-slider"></span>
                                    </label>
                                    <label for="bizVatReg">VAT Registered</label>
                                </div>
                                <p style="font-size:.78rem;color:var(--c-text-soft);margin-top:4px;">
                                    When off, no VAT is computed on sales.
                                </p>
                            </div>
                            <div>
                                <div class="toggle-group">
                                    <label class="toggle-switch">
                                        <input type="checkbox" id="bizVatInc" <?php echo ($biz['vat_inclusive'] ?? 1) ? 'checked' : ''; ?>>
                                        <span class="toggle-slider"></span>
                                    </label>
                                    <label for="bizVatInc">VAT Inclusive Pricing</label>
                                </div>
                                <p style="font-size:.78rem;color:var(--c-text-soft);margin-top:4px;">
                                    When on, selling prices already include VAT.
                                </p>
                            </div>
                        </div>
                        <div class="form-group" style="max-width:200px;margin-top:var(--space-3);">
                            <label class="form-label">VAT Rate</label>
                            <input type="number" id="bizVatRate" class="form-input" value="<?php echo floatval($biz['vat_rate'] ?? 0.12); ?>" step="0.01" min="0" max="1" style="font-family:monospace;">
                            <span style="font-size:.75rem;color:var(--c-text-soft);">e.g. 0.12 = 12%</span>
                        </div>
                    </div>

                    <div class="settings-section">
                        <h4>Receipt Options</h4>
                        <div class="settings-grid">
                            <div class="form-group">
                                <label class="form-label">Receipt Prefix</label>
                                <input type="text" id="bizPrefix" class="form-input" value="<?php echo htmlspecialchars($biz['receipt_prefix'] ?? 'JJ-'); ?>" style="width:120px;font-family:monospace;">
                                <span style="font-size:.75rem;color:var(--c-text-soft);">e.g. JJ- produces JJ-000001</span>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Currency Symbol</label>
                                <input type="text" id="bizCurrency" class="form-input" value="<?php echo htmlspecialchars($biz['currency_symbol'] ?? '₱'); ?>" style="width:80px;font-size:1.1rem;text-align:center;">
                            </div>
                        </div>
                    </div>

                    <div class="settings-section">
                        <h4>Branding</h4>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Business Logo</label>
                            <?php if (!empty($biz['business_logo'])): ?>
                            <div class="mb-2">
                                <img src="<?php echo IMG_URL . '/' . htmlspecialchars($biz['business_logo']); ?>"
                                     alt="Current logo" style="height:48px;object-fit:contain;border:1px solid #dee2e6;border-radius:4px;padding:4px">
                            </div>
                            <?php endif; ?>
                            <input type="file" id="bizLogo" name="business_logo" accept="image/png,image/jpeg,image/gif,image/svg+xml,image/webp" class="form-control">
                            <div class="form-text">PNG/JPG/SVG/WebP, max 2 MB. Shown on receipts, exports, and the navbar.</div>
                        </div>
                    </div>

                    <div id="settingsMsg" style="display:none;margin-bottom:var(--space-4);"></div>

                    <button type="submit" class="btn btn-primary" id="settingsSaveBtn">Save Settings</button>
                </form>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

    </div><!-- /dashboard-container -->

    <!-- Category Modal -->
    <div class="modal" id="categoryModal">
        <div class="modal-content" style="max-width:420px">
            <div class="modal-header">
                <h2 id="categoryModalTitle">Add Category</h2>
                <button class="modal-close" onclick="closeModal('categoryModal')">×</button>
            </div>
            <form id="categoryForm" onsubmit="saveCategory(event)" class="modal-form" style="padding:var(--space-5) var(--space-6)">
                <input type="hidden" id="categoryId">
                <div class="form-group">
                    <label for="categoryName" class="form-label">Category Name</label>
                    <input type="text" id="categoryName" class="form-input" required>
                </div>
                <div class="modal-actions">
                    <button type="submit" class="btn btn-primary">Save</button>
                    <button type="button" class="btn btn-ghost" onclick="closeModal('categoryModal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Supplier Modal -->
    <div class="modal" id="supplierModal">
        <div class="modal-content" style="max-width:460px">
            <div class="modal-header">
                <h2 id="supplierModalTitle">Add Supplier</h2>
                <button class="modal-close" onclick="closeModal('supplierModal')">×</button>
            </div>
            <form id="supplierForm" onsubmit="saveSupplier(event)" class="modal-form" style="padding:var(--space-5) var(--space-6)">
                <input type="hidden" id="supplierId">
                <div class="form-group">
                    <label for="supplierName" class="form-label">Supplier Name</label>
                    <input type="text" id="supplierName" class="form-input" required>
                </div>
                <div class="form-group">
                    <label for="supplierContact" class="form-label">Contact Number</label>
                    <input type="text" id="supplierContact" class="form-input" placeholder="+63-9XX-XXX-XXXX">
                </div>
                <div class="form-group">
                    <label for="supplierEmail" class="form-label">Email</label>
                    <input type="email" id="supplierEmail" class="form-input" placeholder="supplier@example.com">
                </div>
                <div class="modal-actions">
                    <button type="submit" class="btn btn-primary">Save</button>
                    <button type="button" class="btn btn-ghost" onclick="closeModal('supplierModal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script src="<?php echo JS_URL; ?>/main.js"></script>
    <script>
    const CSRF  = '<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>';
    const API   = '<?php echo API_URL; ?>/master-data.php';
    const SAPI  = '<?php echo BASE_URL; ?>/api/settings.php';

    // ── Tab switching ─────────────────────────────────────────
    function switchTab(name, btn) {
        document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        document.getElementById('tab-' + name).classList.add('active');
        btn.classList.add('active');
        history.replaceState(null, '', '?tab=' + name);
    }

    // ── Search / filter ───────────────────────────────────────
    function filterCards(inputId, gridId) {
        const q = document.getElementById(inputId).value.toLowerCase();
        document.querySelectorAll('#' + gridId + ' .master-card').forEach(card => {
            card.style.display = card.dataset.name.includes(q) ? '' : 'none';
        });
    }

    // ── Modal helpers ─────────────────────────────────────────
    function closeModal(id) { document.getElementById(id).classList.remove('active'); }
    document.querySelectorAll('.modal').forEach(m => {
        m.addEventListener('click', e => { if (e.target === m) m.classList.remove('active'); });
    });
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') document.querySelectorAll('.modal.active').forEach(m => m.classList.remove('active'));
    });

    // ── Category CRUD ─────────────────────────────────────────
    function openCategoryModal(mode, id = null, name = '') {
        document.getElementById('categoryId').value = id || '';
        document.getElementById('categoryName').value = name;
        document.getElementById('categoryModalTitle').textContent = mode === 'new' ? 'Add Category' : 'Edit Category';
        document.getElementById('categoryModal').classList.add('active');
        setTimeout(() => document.getElementById('categoryName').focus(), 50);
    }

    function saveCategory(e) {
        e.preventDefault();
        const id = document.getElementById('categoryId').value;
        const fd = new FormData();
        fd.append('csrf_token', CSRF);
        fd.append('type', 'category');
        fd.append('action', id ? 'update' : 'create');
        fd.append('name', document.getElementById('categoryName').value);
        if (id) fd.append('id', id);
        fetch(API, { method:'POST', body:fd })
            .then(r => r.json())
            .then(d => {
                if (d.success) { closeModal('categoryModal'); location.reload(); }
                else alert(d.error || 'Failed');
            });
    }

    // ── Supplier CRUD ─────────────────────────────────────────
    function openSupplierModal(mode, id = null, name = '', contact = '', email = '') {
        document.getElementById('supplierId').value = id || '';
        document.getElementById('supplierName').value = name;
        document.getElementById('supplierContact').value = contact;
        document.getElementById('supplierEmail').value = email;
        document.getElementById('supplierModalTitle').textContent = mode === 'new' ? 'Add Supplier' : 'Edit Supplier';
        document.getElementById('supplierModal').classList.add('active');
        setTimeout(() => document.getElementById('supplierName').focus(), 50);
    }

    function saveSupplier(e) {
        e.preventDefault();
        const id = document.getElementById('supplierId').value;
        const fd = new FormData();
        fd.append('csrf_token', CSRF);
        fd.append('type', 'supplier');
        fd.append('action', id ? 'update' : 'create');
        fd.append('name', document.getElementById('supplierName').value);
        fd.append('contact', document.getElementById('supplierContact').value);
        fd.append('email', document.getElementById('supplierEmail').value);
        if (id) fd.append('id', id);
        fetch(API, { method:'POST', body:fd })
            .then(r => r.json())
            .then(d => {
                if (d.success) { closeModal('supplierModal'); location.reload(); }
                else alert(d.error || 'Failed');
            });
    }

    // ── Generic delete ────────────────────────────────────────
    function deleteItem(type, id, name, productCount) {
        let msg = 'Delete "' + name + '"?';
        if (productCount > 0) msg += '\n\n' + productCount + ' product(s) are linked to this ' + type + '.';
        if (!confirm(msg)) return;
        const fd = new FormData();
        fd.append('csrf_token', CSRF);
        fd.append('type', type);
        fd.append('action', 'delete');
        fd.append('id', id);
        fetch(API, { method:'POST', body:fd })
            .then(r => r.json())
            .then(d => {
                if (d.success) location.reload();
                else alert(d.error || 'Failed');
            });
    }

    // ── Business Settings ─────────────────────────────────────
    function saveSettings(e) {
        e.preventDefault();
        const btn = document.getElementById('settingsSaveBtn');
        const msgEl = document.getElementById('settingsMsg');
        btn.disabled = true;
        btn.textContent = 'Saving...';

        const fd = new FormData();
        fd.append('action', 'save');
        fd.append('csrf_token', CSRF);
        fd.append('business_name',    document.getElementById('bizName').value);
        fd.append('business_address', document.getElementById('bizAddress').value);
        fd.append('tin',              document.getElementById('bizTin').value);
        fd.append('vat_registered',   document.getElementById('bizVatReg').checked ? 1 : 0);
        fd.append('vat_rate',         document.getElementById('bizVatRate').value);
        fd.append('vat_inclusive',     document.getElementById('bizVatInc').checked ? 1 : 0);
        fd.append('receipt_prefix',   document.getElementById('bizPrefix').value);
        fd.append('currency_symbol',  document.getElementById('bizCurrency').value);
        const logoInput = document.getElementById('bizLogo');
        if (logoInput && logoInput.files.length > 0) {
            fd.append('business_logo', logoInput.files[0]);
        }

        fetch(SAPI, { method:'POST', body:fd })
            .then(r => r.json())
            .then(data => {
                btn.disabled = false;
                btn.textContent = 'Save Settings';
                msgEl.style.display = 'block';
                if (data.success) {
                    msgEl.className = 'alert alert-success';
                    msgEl.textContent = data.message || 'Settings saved.';
                } else {
                    msgEl.className = 'alert alert-danger';
                    msgEl.textContent = data.error || 'Failed to save.';
                }
                setTimeout(() => { msgEl.style.display = 'none'; }, 4000);
            })
            .catch(() => {
                btn.disabled = false;
                btn.textContent = 'Save Settings';
                msgEl.style.display = 'block';
                msgEl.className = 'alert alert-danger';
                msgEl.textContent = 'Network error.';
            });
    }
    </script>
</body>
</html>
